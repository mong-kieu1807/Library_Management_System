<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GoogleBooksService
{
    // Same Google Books API key already used by Admin\BookController::fetchByISBN.
    private const API_KEY = 'AIzaSyAEMgJzW_9AU6guBiywIMD6ba6my9deYw4';

    /**
     * Look up an author by name on Google Books. Returns null when Google Books has no
     * record of this author at all — used to validate a manually-typed author name before
     * auto-creating it in the system. Google Books has no dedicated author-photo field, so
     * the cover thumbnail of the best-matching book is used as a stand-in "photo_url".
     */
    public function findAuthorInfo(string $authorName): ?array
    {
        $response = Http::get('https://www.googleapis.com/books/v1/volumes', [
            'q' => 'inauthor:"' . $authorName . '"',
            'key' => self::API_KEY,
            'maxResults' => 1,
        ]);

        $item = $response->json('items.0');
        if (!$item) {
            return null;
        }

        return [
            'photo_url' => $item['volumeInfo']['imageLinks']['thumbnail'] ?? null,
        ];
    }
}
