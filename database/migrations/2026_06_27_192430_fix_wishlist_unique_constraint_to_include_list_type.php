<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TiDB: phải ADD index mới TRƯỚC để FK vẫn có index hỗ trợ,
        // rồi mới DROP index cũ. Nếu drop trước, TiDB báo lỗi 1553.
        Schema::table('wishlists', function (Blueprint $table) {
            $table->unique(['user_id', 'book_id', 'list_type'], 'uq_wishlist_user_book_type');
        });

        Schema::table('wishlists', function (Blueprint $table) {
            $table->dropUnique('uq_wishlist_user_book');
        });
    }

    public function down(): void
    {
        // Rollback: ADD lại (user_id, book_id) TRƯỚC, rồi mới DROP (user_id, book_id, list_type)
        // Lưu ý: rollback sẽ thất bại nếu đã có rows vi phạm (user_id, book_id) duplicate.
        Schema::table('wishlists', function (Blueprint $table) {
            $table->unique(['user_id', 'book_id'], 'uq_wishlist_user_book');
        });

        Schema::table('wishlists', function (Blueprint $table) {
            $table->dropUnique('uq_wishlist_user_book_type');
        });
    }
};
