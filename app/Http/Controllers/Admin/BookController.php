<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Author;
use App\Models\Book;
use App\Models\BookEditHistory;
use App\Models\Publisher;
use Intervention\Image\Laravel\Facades\Image;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;


class BookController extends Controller
{
    public function fetchByISBN(string $isbn)
    {
        $book = Book::where('isbn', $isbn)->with(['authors', 'categories', 'publisher'])->first();
        if ($book) {
            return response()->json($book);
        }

        $apiKey = 'AIzaSyAEMgJzW_9AU6guBiywIMD6ba6my9deYw4';
        $response = Http::get("https://www.googleapis.com/books/v1/volumes?q=isbn:$isbn&key=$apiKey");
        $data = $response->json();

        if (!isset($data['items'][0])) {
            return response()->json([
                'message' => 'Khong tim thay sach voi ISBN nay',
            ], 404);
        }

        $bookData = $data['items'][0]['volumeInfo'];
        $authorName = $bookData['authors'][0] ?? null;
        $publisherName = $bookData['publisher'] ?? null;
        $publishDate = $bookData['publishedDate'] ?? null;

        if ($publishDate) {
            if (strlen($publishDate) === 4) {
                $publishDate .= '-01-01';
            } elseif (strlen($publishDate) === 7) {
                $publishDate .= '-01';
            }
        }

        $author = Author::firstOrCreate([
            'author_name' => $authorName,
        ]);

        $publisher = Publisher::firstOrCreate([
            'name' => $publisherName,
        ]);

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

        return response()->json(Book::with(['authors', 'categories', 'publisher'])->find($book->book_id));
    }

    public function index()
    {
        return Book::with(['authors', 'categories', 'publisher'])->paginate(20);
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'isbn' => ['required', 'string', 'max:20', 'unique:books,isbn'],
            'publisher_id' => ['required', 'exists:publishers,publisher_id'],
            'authors' => ['nullable', 'array'],
            'authors.*' => ['integer', 'exists:authors,author_id'],
            'publish_date' => ['nullable', 'date'],
            'publish_year' => ['nullable', 'integer'],
            'edition' => ['nullable', 'string', 'max:50'],
            'language' => ['nullable', 'string', 'max:50'],
            'pages' => ['nullable', 'integer', 'min:0'],
            'dimensions' => ['nullable', 'string', 'max:100'],
            'cover_type' => ['nullable', 'string', 'max:50'],
            'description' => ['nullable', 'string'],
            'cover_image' => ['nullable', 'image', 'max:2048'],
            'replacement_cost' => ['nullable', 'numeric', 'min:0'],
            'is_featured' => ['boolean'],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['integer', 'exists:categories,category_id'],
        ]);
        if ($request->hasFile('cover_image')) {

            $image = Image::read($request->file('cover_image'));

            // crop và resize về kích thước cố định
            $image->cover(300, 450);

            $filename = time().'.jpg';

            $image->save(
                storage_path('app/public/book-covers/'.$filename)
            );

            $validated['cover_image'] = 'book-covers/'.$filename;
        }
        $book = DB::transaction(function () use ($validated) {
            $book = Book::create([
            'title' => $validated['title'],
            'isbn' => $validated['isbn'],
            'publisher_id' => $validated['publisher_id'],

            'publish_date' => $validated['publish_date'] ?? null,
            'publish_year' => $validated['publish_year'] ?? null,
            'edition' => $validated['edition'] ?? null,
            'language' => $validated['language'] ?? null,
            'pages' => $validated['pages'] ?? null,
            'dimensions' => $validated['dimensions'] ?? null,
            'cover_type' => $validated['cover_type'] ?? null,
            'description' => $validated['description'] ?? null,
            'replacement_cost' => $validated['replacement_cost'] ?? null,
            'cover_image' => $validated['cover_image'] ?? null,
        ]);

            $book->authors()->sync($validated['authors'] ?? []);
            $book->categories()->sync($validated['categories'] ?? []);

            return $book;
        });

        return response()->json($book->load(['authors', 'categories', 'publisher']), 201);
    }

    public function show(int $id)
    {
        return Book::with(['authors', 'categories', 'publisher'])->findOrFail($id);
    }

    public function edit(int $id)
    {
        return Book::findOrFail($id);
    }

    public function update(Request $request, int $id)
    {
        $book = Book::with(['authors', 'categories'])->findOrFail($id);

        $validated = $request->validate([
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
            'cover_image' => ['sometimes', 'nullable', 'string', 'max:255'],
            'avg_rating' => ['sometimes', 'nullable', 'numeric', 'between:0,9.9'],
            'total_reviews' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'replacement_cost' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'is_featured' => ['sometimes', 'boolean'],
            'authors' => ['sometimes', 'array'],
            'authors.*' => ['integer', 'exists:authors,author_id'],
            'categories' => ['sometimes', 'array'],
            'categories.*' => ['integer', 'exists:categories,category_id'],
            'edited_by' => ['nullable', 'exists:users,user_id'],
            'edit_reason' => ['nullable', 'string'],
        ]);

        $editedBy = $request->user()?->getAuthIdentifier() ?? $request->input('edited_by');
        if (!$editedBy) {
            return response()->json([
                'message' => 'Vui long cung cap nguoi chinh sua (edited_by) hoac dang nhap truoc khi sua sach.',
            ], 422);
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

        foreach ($editableFields as $field) {
            if (!$request->exists($field)) {
                continue;
            }

            $oldValue = $book->getAttribute($field);
            $newValue = $validated[$field] ?? null;

            if ($this->formatHistoryValue($oldValue) === $this->formatHistoryValue($newValue)) {
                continue;
            }

            $updateData[$field] = $newValue;
            $changes[] = $this->makeHistoryRow($book->book_id, $editedBy, $field, $oldValue, $newValue, $request->input('edit_reason'));
        }

        if ($request->exists('authors')) {
            $oldAuthors = $book->authors->pluck('author_id')->sort()->values()->all();
            $newAuthors = collect($validated['authors'] ?? [])->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

            if ($oldAuthors !== $newAuthors) {
                $changes[] = $this->makeHistoryRow($book->book_id, $editedBy, 'authors', $oldAuthors, $newAuthors, $request->input('edit_reason'));
            }
        }

        if ($request->exists('categories')) {
            $oldCategories = $book->categories->pluck('category_id')->sort()->values()->all();
            $newCategories = collect($validated['categories'] ?? [])->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

            if ($oldCategories !== $newCategories) {
                $changes[] = $this->makeHistoryRow($book->book_id, $editedBy, 'categories', $oldCategories, $newCategories, $request->input('edit_reason'));
            }
        }

        DB::transaction(function () use ($book, $updateData, $request, $validated, $changes) {
            if (!empty($updateData)) {
                $book->update($updateData);
            }

            if ($request->exists('authors')) {
                $book->authors()->sync($validated['authors'] ?? []);
            }

            if ($request->exists('categories')) {
                $book->categories()->sync($validated['categories'] ?? []);
            }

            foreach ($changes as $change) {
                BookEditHistory::create($change);
            }
        });

        return response()->json(
            Book::with(['authors', 'categories', 'publisher', 'bookEditHistories.user'])->findOrFail($id),
            200
        );
    }

    public function destroy(int $id)
    {
        $book = Book::findOrFail($id);
        if($book->bookCopies()->where('status', '=', 'borrowing')->exists()){
            return response()->json(['message' => 'Không thể xóa sách này vì còn bản sao sách đang được mượn'], 400);
        }
        else if($book->bookCopies()->where('status', '=', 'reserved')->exists()){
            return response()->json(['message' => 'Không thể xóa sách này vì còn bản sao sách đang được đặt trước'], 400);
        }
        $book->delete();

        return response()->json(['message' => 'Xóa thành công']);
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
