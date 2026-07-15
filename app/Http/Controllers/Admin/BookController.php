<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\AuthorNotFoundException;
use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookEditHistory;
use App\Models\Publisher;
use App\Services\ActivityLogService;
use App\Services\GoogleBooksService;
use Intervention\Image\Laravel\Facades\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;


class BookController extends Controller
{
    public function __construct(
        private GoogleBooksService $googleBooksService,
        private ActivityLogService $activityLogService
    ) {
    }

    /**
     * Resolve manually-typed author names to author_id's, auto-creating any author that
     * doesn't exist yet in the system provided Google Books confirms they're a real author
     * (its book-cover thumbnail is stored as a stand-in avatar). Throws when a name matches
     * neither the local system nor Google Books, so the caller can reject the request.
     *
     * @param string[] $names
     * @return int[]
     * @throws AuthorNotFoundException
     */
    private function resolveAuthorIds(array $names): array
    {
        $ids = [];

        foreach ($names as $rawName) {
            $name = trim((string) $rawName);
            if ($name === '') {
                continue;
            }

            $existing = Author::whereRaw('LOWER(author_name) = ?', [mb_strtolower($name)])->first();
            if ($existing) {
                $ids[] = $existing->author_id;
                continue;
            }

            $info = $this->googleBooksService->findAuthorInfo($name);
            if ($info === null) {
                throw new AuthorNotFoundException(
                    "Không tìm thấy tác giả \"{$name}\" trên Google Books. Vui lòng kiểm tra lại tên hoặc chọn tác giả có sẵn trong hệ thống."
                );
            }

            $newAuthor = Author::firstOrCreate(
                ['author_name' => $name],
                ['avatar_url' => $info['photo_url'] ?? null, 'is_active' => true]
            );
            $ids[] = $newAuthor->author_id;
        }

        if (empty($ids)) {
            throw new AuthorNotFoundException('Vui lòng cung cấp ít nhất một tác giả hợp lệ.');
        }

        return array_values(array_unique($ids));
    }

    public function fetchByISBN(string $isbn)
    {
        $book = Book::where('isbn', $isbn)->with(['authors', 'categories', 'publisher'])->first();
        if ($book) {
            return response()->json([
                'message' => 'Sách này đã tồn tại trong hệ thống (trùng mã ISBN).',
                'errors' => [
                    'isbn' => ['Sách này đã tồn tại trong hệ thống (trùng mã ISBN).']
                ]
            ], 422);
        }

        $apiKey = 'AIzaSyAEMgJzW_9AU6guBiywIMD6ba6my9deYw4';
        $response = Http::get("https://www.googleapis.com/books/v1/volumes?q=isbn:$isbn&key=$apiKey");
        $data = $response->json();

        if (!isset($data['items'][0])) {
            return response()->json([
                'message' => 'Không tìm thấy sách với ISBN này.',
            ], 404);
        }

        $bookData = $data['items'][0]['volumeInfo'];
        $authorName = $bookData['authors'][0] ?? 'Không rõ tác giả';
        $publisherName = $bookData['publisher'] ?? 'Không rõ nhà xuất bản';
        $publishDate = $bookData['publishedDate'] ?? null;

        if ($publishDate) {
            if (strlen($publishDate) === 4) {
                $publishDate .= '-01-01';
            } elseif (strlen($publishDate) === 7) {
                $publishDate .= '-01';
            }
        }

        try {
            $author = Author::firstOrCreate([
                'author_name' => $authorName,
            ]);

            $publisher = Publisher::firstOrCreate([
                'name' => $publisherName,
            ]);

            $book = DB::transaction(function () use ($bookData, $isbn, $publisher, $author, $publishDate) {
                $book = Book::create([
                    'title' => $bookData['title'] ?? null,
                    'isbn' => $isbn,
                    'publisher_id' => $publisher->publisher_id,
                    'author_id' => $author->author_id,
                    'publish_date' => $publishDate,
                    'pages' => $bookData['pageCount'] ?? null,
                    'description' => $bookData['description'] ?? null,
                    'cover_image' => $bookData['imageLinks']['thumbnail'] ?? null,
                    'language' => $bookData['language'] ?? null,
                    'avg_rating' => $bookData['averageRating'] ?? null,
                    'total_reviews' => $bookData['ratingsCount'] ?? null,
                    'replacement_cost' => 80000,
                    'dimensions' => '13x20cm',
                    'cover_type' => 'Bia mem',
                ]);
                $book->authors()->sync([$author->author_id]);

                // Auto Import luôn tạo sẵn 1 bản sao với barcode tự sinh, cùng cách với "Thêm sách thủ công".
                BookCopy::create([
                    'book_id' => $book->book_id,
                    'barcode' => BookCopy::generateUniqueBarcode(),
                    'status' => 'available',
                    'condition' => 'good',
                    'shelf_location' => null,
                    'acquisition_date' => now()->toDateString(),
                ]);

                return $book;
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains($e->getMessage(), 'isbn')) {
                return response()->json([
                    'message' => 'Sách này đã tồn tại trong hệ thống (trùng mã ISBN).',
                ], 422);
            }

            return response()->json([
                'message' => 'Không thể thêm sách tự động do lỗi dữ liệu từ Google Books. Vui lòng thêm sách thủ công.',
            ], 422);
        }

        return response()->json(Book::with(['authors', 'categories', 'publisher', 'bookCopies'])->find($book->book_id));
    }

    public function index(Request $request)
    {
        $q = trim($request->query('q', ''));
        $query = Book::with(['authors', 'categories', 'publisher']);

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('title', 'like', "%{$q}%")
                    ->orWhere('isbn', 'like', "%{$q}%")
                    ->orWhereHas('authors', function ($authorSub) use ($q) {
                        $authorSub->where('author_name', 'like', "%{$q}%");
                    })
                    ->orWhereHas('publisher', function ($pubSub) use ($q) {
                        $pubSub->where('name', 'like', "%{$q}%");
                    });
            });
        }

        if ($request->boolean('is_featured')) {
            $query->where('is_featured', true);
        }

        return $query->paginate(20);
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => ['required', 'string', 'max:255'],
            'isbn' => ['required', 'string', 'max:20', 'unique:books,isbn'],
            'publisher_id' => ['required', 'exists:publishers,publisher_id'],
            'authors' => ['required', 'array', 'min:1'],
            'authors.*' => ['string', 'max:255'],
            'publish_date' => ['nullable', 'date'],
            'publish_year' => ['nullable', 'integer'],
            'edition' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', 'max:50'],
            'pages' => ['nullable', 'integer', 'min:0'],
            'dimensions' => ['nullable', 'string', 'max:100'],
            'cover_type' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'cover_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'replacement_cost' => ['nullable', 'numeric', 'min:0'],
            'is_featured' => ['boolean'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['integer', 'exists:categories,category_id'],
            'create_first_copy' => ['sometimes', 'boolean'],
            'barcode' => ['nullable', 'string', 'max:64', 'unique:book_copies,barcode'],
            'shelf_location' => ['nullable', 'string', 'max:100'],
        ], [
            'isbn.unique' => 'Sách này đã tồn tại trong hệ thống (trùng mã ISBN).',
            'barcode.unique' => 'Mã vạch này đã tồn tại trong hệ thống.',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        // Authors are submitted as names (existing or brand new) — resolve to IDs before
        // touching the disk/DB so an unrecognized author name fails fast.
        try {
            $authorIds = $this->resolveAuthorIds($validated['authors']);
        } catch (AuthorNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $createFirstCopy = $request->boolean('create_first_copy', true);

        $coverImagePath = null;
        if ($request->hasFile('cover_image')) {
            $image = Image::read($request->file('cover_image'));

            // crop và resize về kích thước cố định
            $image->cover(300, 450);

            $filename = time().'_'.Str::random(8).'.jpg';

            $image->save(
                storage_path('app/public/book-covers/'.$filename)
            );

            $coverImagePath = 'book-covers/'.$filename;
        }

        try {
            $book = DB::transaction(function () use ($validated, $coverImagePath, $createFirstCopy, $authorIds) {
                $book = Book::create([
                'title' => $validated['title'],
                'isbn' => $validated['isbn'],
                'publisher_id' => $validated['publisher_id'],
                'author_id' => $authorIds[0],

                'publish_date' => $validated['publish_date'] ?? null,
                'publish_year' => $validated['publish_year'] ?? null,
                'edition' => $validated['edition'] ?? null,
                'language' => $validated['language'] ?? null,
                'pages' => $validated['pages'] ?? null,
                'dimensions' => $validated['dimensions'] ?? null,
                'cover_type' => $validated['cover_type'] ?? null,
                'description' => $validated['description'] ?? null,
                'replacement_cost' => $validated['replacement_cost'] ?? null,
                'cover_image' => $coverImagePath,
            ]);

                $book->authors()->sync($authorIds);
                $book->categories()->sync($validated['categories'] ?? []);

                if ($createFirstCopy) {
                    $barcode = $validated['barcode'] ?? null;
                    if (!$barcode) {
                        $barcode = BookCopy::generateUniqueBarcode();
                    }

                    BookCopy::create([
                        'book_id' => $book->book_id,
                        'barcode' => $barcode,
                        'status' => 'available',
                        'condition' => 'good',
                        'shelf_location' => $validated['shelf_location'] ?? null,
                        'acquisition_date' => now()->toDateString(),
                    ]);
                }

                return $book;
            });
        } catch (\Throwable $e) {
            // Roll back the on-disk upload too — the DB transaction already rolled back the rows.
            if ($coverImagePath) {
                Storage::disk('public')->delete($coverImagePath);
            }
            throw $e;
        }

        return response()->json($book->load(['authors', 'categories', 'publisher', 'bookCopies']), 201);
    }

    public function show(int $id)
    {
        return Book::with(['authors', 'categories', 'publisher', 'bookCopies', 'bookEditHistories.user'])->findOrFail($id);
    }

    public function edit(int $id)
    {
        return Book::findOrFail($id);
    }

    public function update(Request $request, int $id)
    {
        $book = Book::with(['authors', 'categories'])->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'isbn' => [
                'sometimes',
                'nullable',
                'string',
                'max:20',
                Rule::unique('books', 'isbn')->ignore($book->book_id, 'book_id'),
            ],
            'publisher_id' => ['sometimes', 'nullable', 'exists:publishers,publisher_id'],
            'publish_date' => ['sometimes', 'nullable', 'date'],
            'publish_year' => ['sometimes', 'nullable', 'integer'],
            'edition' => ['sometimes', 'nullable', 'string', 'max:50'],
            'language' => ['sometimes', 'nullable', 'string', 'max:50'],
            'pages' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'dimensions' => ['sometimes', 'nullable', 'string', 'max:100'],
            'cover_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string'],
            'avg_rating' => ['sometimes', 'nullable', 'numeric', 'between:0,9.9'],
            'total_reviews' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'replacement_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_featured' => ['sometimes', 'boolean'],
            'authors' => ['sometimes', 'array'],
            'authors.*' => ['string', 'max:255'],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['integer', 'exists:categories,category_id'],
            'edited_by' => ['nullable', 'exists:users,user_id'],
            'edit_reason' => ['nullable', 'string'],
        ], [
            'isbn.unique' => 'Sách này đã tồn tại trong hệ thống (trùng mã ISBN).',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();

        $editedBy = $request->user()?->getAuthIdentifier() ?? $request->input('edited_by');
        if (!$editedBy) {
            return response()->json([
                'message' => 'Vui long cung cap nguoi chinh sua (edited_by) hoac dang nhap truoc khi sua sach.',
            ], 422);
        }

        // Authors are submitted as names — resolve to IDs, auto-creating unknown-but-real
        // (per Google Books) authors, same as store().
        $resolvedAuthorIds = null;
        if ($request->exists('authors')) {
            try {
                $resolvedAuthorIds = $this->resolveAuthorIds($validated['authors'] ?? []);
            } catch (AuthorNotFoundException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        // Cover image: new file upload, explicit removal, or (back-compat) a raw string/URL passthrough.
        $coverImageProvided = false;
        $newCoverImagePath = null;
        $uploadedCoverImagePath = null;
        $oldCoverImagePathForCleanup = null;

        if ($request->hasFile('cover_image')) {
            $request->validate([
                'cover_image' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            ]);

            $image = Image::read($request->file('cover_image'));
            $image->cover(300, 450);
            $filename = time().'_'.Str::random(8).'.jpg';
            $image->save(storage_path('app/public/book-covers/'.$filename));

            $newCoverImagePath = 'book-covers/'.$filename;
            $uploadedCoverImagePath = $newCoverImagePath;
            $coverImageProvided = true;
            $oldCoverImagePathForCleanup = $book->cover_image;
        } elseif ($request->boolean('remove_cover_image')) {
            $newCoverImagePath = null;
            $coverImageProvided = true;
            $oldCoverImagePathForCleanup = $book->cover_image;
        } elseif ($request->exists('cover_image')) {
            $request->validate([
                'cover_image' => ['nullable', 'string', 'max:255'],
            ]);
            $newCoverImagePath = $request->input('cover_image');
            $coverImageProvided = true;
        }

        $editableFields = [
            'title',
            'isbn',
            'publisher_id',
            'publish_date',
            'publish_year',
            'edition',
            'language',
            'pages',
            'dimensions',
            'cover_type',
            'description',
            'cover_image',
            'avg_rating',
            'total_reviews',
            'replacement_cost',
            'is_featured',
        ];

        $changes = [];
        $updateData = [];

        // Pre-load name maps so IDs are stored as human-readable names in history
        $publisherMap = DB::table('publishers')->pluck('name', 'publisher_id');
        $authorMap    = DB::table('authors')->pluck('author_name', 'author_id');
        $categoryMap  = DB::table('categories')->pluck('category_name', 'category_id');

        foreach ($editableFields as $field) {
            if ($field === 'cover_image') {
                if (!$coverImageProvided) {
                    continue;
                }
                $oldValue = $book->getAttribute('cover_image');
                $newValue = $newCoverImagePath;
            } else {
                if (!$request->exists($field)) {
                    continue;
                }

                $oldValue = $book->getAttribute($field);
                $newValue = $validated[$field] ?? null;
            }

            if ($this->formatHistoryValue($oldValue) === $this->formatHistoryValue($newValue)) {
                continue;
            }

            $updateData[$field] = $newValue;

            // Resolve IDs to display names before recording
            $oldDisplay = $this->resolveFieldDisplay($field, $oldValue, $publisherMap, $authorMap);
            $newDisplay = $this->resolveFieldDisplay($field, $newValue, $publisherMap, $authorMap);
            $changes[] = $this->makeHistoryRow($book->book_id, $editedBy, $field, $oldDisplay, $newDisplay, $request->input('edit_reason'));
        }

        if ($request->exists('authors')) {
            $oldAuthors = $book->authors->pluck('author_id')->sort()->values()->all();
            $newAuthors = collect($resolvedAuthorIds ?? [])->unique()->sort()->values()->all();

            if ($oldAuthors !== $newAuthors) {
                $oldAuthorNames = $book->authors->pluck('author_name')->sort()->values()->all();
                $newAuthorNames = collect($newAuthors)->map(fn ($id) => $authorMap[$id] ?? "ID:{$id}")->sort()->values()->all();
                $changes[] = $this->makeHistoryRow($book->book_id, $editedBy, 'authors', $oldAuthorNames, $newAuthorNames, $request->input('edit_reason'));
            }

            if (!empty($resolvedAuthorIds)) {
                $primaryAuthorId = (int) $resolvedAuthorIds[0];
                if ($book->author_id !== $primaryAuthorId) {
                    $updateData['author_id'] = $primaryAuthorId;
                    $oldAuthorName = $authorMap[$book->author_id] ?? "ID:{$book->author_id}";
                    $newAuthorName = $authorMap[$primaryAuthorId] ?? "ID:{$primaryAuthorId}";
                    $changes[] = $this->makeHistoryRow($book->book_id, $editedBy, 'author_id', $oldAuthorName, $newAuthorName, $request->input('edit_reason'));
                }
            }
        }

        if ($request->exists('categories')) {
            $oldCategories = $book->categories->pluck('category_id')->sort()->values()->all();
            $newCategories = collect($validated['categories'] ?? [])->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

            if ($oldCategories !== $newCategories) {
                $oldCategoryNames = $book->categories->pluck('category_name')->sort()->values()->all();
                $newCategoryNames = collect($newCategories)->map(fn ($id) => $categoryMap[$id] ?? "ID:{$id}")->sort()->values()->all();
                $changes[] = $this->makeHistoryRow($book->book_id, $editedBy, 'categories', $oldCategoryNames, $newCategoryNames, $request->input('edit_reason'));
            }
        }

        try {
            DB::transaction(function () use ($book, $updateData, $request, $validated, $changes, $resolvedAuthorIds) {
                if (!empty($updateData)) {
                    $book->update($updateData);
                }

                if ($request->exists('authors')) {
                    $book->authors()->sync($resolvedAuthorIds ?? []);
                }

                if ($request->exists('categories')) {
                    $book->categories()->sync($validated['categories'] ?? []);
                }

                foreach ($changes as $change) {
                    BookEditHistory::create($change);
                }
            });
        } catch (\Throwable $e) {
            // Roll back the newly uploaded file — the DB transaction already rolled back the rows,
            // and the old cover (if any) was never touched.
            if ($uploadedCoverImagePath) {
                Storage::disk('public')->delete($uploadedCoverImagePath);
            }
            throw $e;
        }

        // Only clean up the previous cover after a successful commit, and only if it was a
        // locally-stored file — imported covers can point at external URLs we don't own.
        if ($oldCoverImagePathForCleanup && !str_starts_with($oldCoverImagePathForCleanup, 'http')) {
            Storage::disk('public')->delete($oldCoverImagePathForCleanup);
        }

        // Module 7 — Activity Log: ghi 1 dòng log gộp cho toàn bộ trường đã đổi,
        // tái dùng $changes (đã tính sẵn cho book_edit_histories) thay vì tính lại.
        if (!empty($changes)) {
            $auditPayload = self::buildBookAuditPayload($changes);
            $this->activityLogService->bookUpdated($editedBy, $book->book_id, $auditPayload['old'], $auditPayload['new'], $request->ip());
        }

        return response()->json(
            Book::with(['authors', 'categories', 'publisher', 'bookEditHistories.user'])->findOrFail($id),
            200
        );
    }

    public function destroy(int $id)
    {
        $book = Book::findOrFail($id);
        if($book->bookCopies()->where('status', '=', 'borrowed')->exists()){
            return response()->json(['message' => 'Không thể xóa sách này vì còn bản sao sách đang được mượn'], 400);
        }
        else if($book->bookCopies()->where('status', '=', 'reserved')->exists()){
            return response()->json(['message' => 'Không thể xóa sách này vì còn bản sao sách đang được đặt trước'], 400);
        }
        $book->delete();

        return response()->json(['message' => 'Xóa thành công']);
    }

    /**
     * Gom các dòng $changes (field_name/old_value/new_value) thành 2 mảng phẳng
     * before/after cho audit_logs. Tách riêng để test được mà không cần DB.
     *
     * @param array<int, array{field_name:string, old_value:mixed, new_value:mixed}> $changes
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    private static function buildBookAuditPayload(array $changes): array
    {
        $old = [];
        $new = [];
        foreach ($changes as $change) {
            $old[$change['field_name']] = $change['old_value'];
            $new[$change['field_name']] = $change['new_value'];
        }

        return ['old' => $old, 'new' => $new];
    }

    private function makeHistoryRow(int $bookId, int $editedBy, string $field, mixed $oldValue, mixed $newValue, ?string $reason): array
    {
        return [
            'book_id' => $bookId,
            'edited_by' => $editedBy,
            'field_name' => $field,
            'old_value' => $this->formatHistoryValue($oldValue),
            'new_value' => $this->formatHistoryValue($newValue),
            'edit_reason' => $reason,
            'edited_at' => now(),
        ];
    }

    private function resolveFieldDisplay(string $field, mixed $value, $publisherMap, $authorMap): mixed
    {
        if ($value === null) return null;
        if ($field === 'publisher_id') return $publisherMap[$value] ?? "ID:{$value}";
        if ($field === 'author_id')    return $authorMap[$value]    ?? "ID:{$value}";
        return $value;
    }

    private function formatHistoryValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            return json_encode(array_values($value), JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }
}
