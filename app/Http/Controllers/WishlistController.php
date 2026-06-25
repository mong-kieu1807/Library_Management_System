<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WishlistController extends Controller
{
    private const STATUS_TO_DB = [
        'favorite'     => 'favorite',
        'want_to_read' => 'want_to_read',
        'reading'      => 'reading',
        'finished'     => 'read',
    ];

    private const STATUS_TO_FE = [
        'favorite'     => ['value' => 'favorite',     'label' => 'Yêu thích'],
        'want_to_read' => ['value' => 'want_to_read', 'label' => 'Đọc sau'],
        'reading'      => ['value' => 'reading',      'label' => 'Đang đọc'],
        'read'         => ['value' => 'finished',     'label' => 'Đã đọc'],
    ];

    private function baseSelect(): array
    {
        return [
            'wl.wishlist_id',
            'wl.book_id',
            'wl.list_type',
            'wl.note',
            DB::raw("DATE_FORMAT(wl.created_at, '%Y-%m-%d') as created_at"),
            'b.title',
            'b.cover_image',
            DB::raw('(SELECT a.author_name FROM book_authors ba JOIN authors a ON a.author_id = ba.author_id WHERE ba.book_id = wl.book_id LIMIT 1) as author_name'),
            DB::raw('(SELECT ROUND(COALESCE(AVG(r.rating), 0), 1) FROM reviews r WHERE r.book_id = wl.book_id) as avg_rating'),
            DB::raw("(SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id = wl.book_id AND bc.status = 'available') as available_copies"),
        ];
    }

    private function buildItem(object $row): array
    {
        return [
            'wishlist_id'      => $row->wishlist_id,
            'book_id'          => $row->book_id,
            'title'            => $row->title,
            'cover_image'      => $row->cover_image,
            'author_name'      => $row->author_name,
            'avg_rating'       => $row->avg_rating !== null ? (float) $row->avg_rating : null,
            'available_copies' => (int) $row->available_copies,
            'status'           => self::STATUS_TO_FE[$row->list_type],
            'note'             => $row->note,
            'created_at'       => $row->created_at,
        ];
    }

    private function fetchOne(int $wishlistId): ?object
    {
        return DB::table('wishlists as wl')
            ->join('books as b', 'b.book_id', '=', 'wl.book_id')
            ->where('wl.wishlist_id', $wishlistId)
            ->select($this->baseSelect())
            ->first();
    }

    /**
     * GET /v1/me/wishlist
     */
    public function index(Request $request)
    {
        $userId = auth()->id();

        $rows = DB::table('wishlists as wl')
            ->join('books as b', 'b.book_id', '=', 'wl.book_id')
            ->where('wl.user_id', $userId)
            ->select($this->baseSelect())
            ->orderByDesc('wl.wishlist_id')
            ->get();

        return response()->json([
            'data' => $rows->map(fn($row) => $this->buildItem($row)),
        ]);
    }

    /**
     * POST /v1/me/wishlist
     */
    public function store(Request $request)
    {
        $userId = auth()->id();

        $validated = $request->validate([
            'book_id' => 'required|integer|exists:books,book_id',
            'status'  => 'required|in:favorite,want_to_read,reading,finished',
            'note'    => 'nullable|string|max:1000',
        ]);

        $dbListType = self::STATUS_TO_DB[$validated['status']];

        $existing = DB::table('wishlists')
            ->where('user_id', $userId)
            ->where('book_id', $validated['book_id'])
            ->where('list_type', $dbListType)
            ->first();

        if ($existing) {
            return response()->json(['message' => 'Sách đã có trong danh sách này.'], 409);
        }

        $wishlistId = DB::table('wishlists')->insertGetId([
            'user_id'    => $userId,
            'book_id'    => $validated['book_id'],
            'list_type'  => $dbListType,
            'note'       => $validated['note'] ?? null,
            'is_public'  => false,
            'created_at' => now(),
        ]);

        $row = $this->fetchOne($wishlistId);

        return response()->json([
            'message' => 'Đã thêm vào danh sách đọc.',
            'data'    => $this->buildItem($row),
        ], 201);
    }

    /**
     * PATCH /v1/me/wishlist/{wishlistId}
     */
    public function update(Request $request, int $wishlistId)
    {
        $userId = auth()->id();

        $item = DB::table('wishlists')
            ->where('wishlist_id', $wishlistId)
            ->where('user_id', $userId)
            ->first();

        if (!$item) {
            return response()->json(['message' => 'Không tìm thấy mục trong danh sách đọc.'], 404);
        }

        $validated = $request->validate([
            'status' => 'sometimes|in:favorite,want_to_read,reading,finished',
            'note'   => 'sometimes|nullable|string|max:1000',
        ]);

        if (empty($validated)) {
            return response()->json(['message' => 'Không có dữ liệu để cập nhật.'], 422);
        }

        $updateData = [];
        if (isset($validated['status'])) {
            $updateData['list_type'] = self::STATUS_TO_DB[$validated['status']];
        }
        if (array_key_exists('note', $validated)) {
            $updateData['note'] = $validated['note'];
        }

        DB::table('wishlists')
            ->where('wishlist_id', $wishlistId)
            ->update($updateData);

        $row = $this->fetchOne($wishlistId);

        return response()->json([
            'message' => 'Đã cập nhật.',
            'data'    => $this->buildItem($row),
        ]);
    }

    /**
     * DELETE /v1/me/wishlist/{wishlistId}
     */
    public function destroy(int $wishlistId)
    {
        $userId = auth()->id();

        $item = DB::table('wishlists')
            ->where('wishlist_id', $wishlistId)
            ->where('user_id', $userId)
            ->first();

        if (!$item) {
            return response()->json(['message' => 'Không tìm thấy mục trong danh sách đọc.'], 404);
        }

        DB::table('wishlists')
            ->where('wishlist_id', $wishlistId)
            ->delete();

        return response()->json(['message' => 'Đã xóa khỏi danh sách đọc.']);
    }

    /**
     * POST /v1/me/favorites/share
     * share_token is UNIQUE per row — store token on ONE anchor row only.
     */
    public function share()
    {
        $userId = auth()->id();

        // TC2: Reuse existing token if any favorite row already has one
        $existingToken = DB::table('wishlists')
            ->where('user_id', $userId)
            ->where('list_type', 'favorite')
            ->whereNotNull('share_token')
            ->value('share_token');

        if ($existingToken) {
            return response()->json(['success' => true, 'token' => $existingToken]);
        }

        $anchor = DB::table('wishlists')
            ->where('user_id', $userId)
            ->where('list_type', 'favorite')
            ->orderBy('wishlist_id')
            ->first();

        if (!$anchor) {
            return response()->json(['message' => 'Bạn chưa có sách yêu thích nào.'], 422);
        }

        $token = (string) \Illuminate\Support\Str::uuid();

        DB::table('wishlists')
            ->where('wishlist_id', $anchor->wishlist_id)
            ->update(['is_public' => true, 'share_token' => $token]);

        return response()->json(['success' => true, 'token' => $token]);
    }

    /**
     * GET /v1/public/shared/favorites/{token}
     * No auth required. Finds owner from the anchor row, returns all their favorites.
     */
    public function publicView(string $token)
    {
        $anchor = DB::table('wishlists')
            ->where('share_token', $token)
            ->where('is_public', true)
            ->first();

        if (!$anchor) {
            return response()->json(['message' => 'Danh sách không tìm thấy hoặc không còn công khai.'], 404);
        }

        $userId    = $anchor->user_id;
        $ownerName = DB::table('users')->where('user_id', $userId)->value('full_name');

        $rows = DB::table('wishlists as wl')
            ->join('books as b', 'b.book_id', '=', 'wl.book_id')
            ->where('wl.user_id', $userId)
            ->where('wl.list_type', 'favorite')
            ->select([
                'wl.book_id',
                'b.title',
                'b.cover_image',
                DB::raw('(SELECT a.author_name FROM book_authors ba JOIN authors a ON a.author_id = ba.author_id WHERE ba.book_id = wl.book_id LIMIT 1) as author_name'),
                DB::raw('(SELECT ROUND(COALESCE(AVG(r.rating), 0), 1) FROM reviews r WHERE r.book_id = wl.book_id) as avg_rating'),
                DB::raw("(SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id = wl.book_id AND bc.status = 'available') as available_copies"),
            ])
            ->get();

        return response()->json([
            'data' => [
                'owner_name' => $ownerName,
                'total'      => $rows->count(),
                'books'      => $rows->map(fn($row) => [
                    'book_id'          => $row->book_id,
                    'title'            => $row->title,
                    'cover_image'      => $row->cover_image,
                    'author_name'      => $row->author_name,
                    'avg_rating'       => $row->avg_rating !== null ? (float) $row->avg_rating : null,
                    'available_copies' => (int) $row->available_copies,
                ]),
            ],
        ]);
    }
}
