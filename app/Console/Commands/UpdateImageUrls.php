<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateImageUrls extends Command
{
    protected $signature = 'images:update-urls';
    protected $description = 'Update book cover_image URLs to GitHub raw';

    public function handle(): void
    {
        $base = 'https://raw.githubusercontent.com/mong-kieu1807/library-assets/main/';

        $map = [
            1  => 'mat-biec.jpg',
            2  => 'Toi-thay-hoa-vang-tren-co-xanh.jpg',
            3  => 'chi-pheo.jpg',
            4  => 'lao-hac.jpg',
            5  => 'tat-den.jpg',
            6  => 'So-do.jpg',
            7  => 'de-men-phieu-luu-ky.jpg',
            8  => 'gio-lanh-dau-mua.jpg',
            9  => 'vang-bong-mot-thoi.jpg',
            10 => 'tho-tho.jpg',
            11 => 'lua-thieng.jpg',
            12 => 'truyen-kieu.jpg',
            13 => 'tho-ho-xuan-huong.jpg',
            14 => 'noi-buon-chien-tranh.jpg',
            15 => null, // Cánh đồng bất tận — chưa có ảnh
            16 => 'harry-potter-va-hon-da-phu-thuy.jpg',
            17 => 'chua_te_cua_nhung_chiec_nhan.jpg',
            18 => 'tro-choi-vuong-quyen.jpg',
            19 => 'an-mang-tren-chuyen-tau-toc-hanh.jpg',
            20 => 'sherlock-holme.jpg',
            21 => 'it.jpg',
            22 => 'mat_ma_davinci.jpg',
            23 => 'rung_nauy.jpg',
            24 => 'phia-sau-nghi-can-x.jpg',
            25 => 'neu-em-khong-phai-mot-giac-mo.jpg',
            26 => 'ngay-mai.jpg',
            27 => 'nha-gia-kim.jpg',
            28 => 'nhung-nguoi-khon-kho.jpg',
            29 => 'chien-tranh-va-hoa-binh.jpg',
            30 => 'toi-ac-va-hinh-phat.jpg',
            31 => 'nhung-cuoc-phieu-luu-cua-tom-sawyer.jpg',
            32 => 'ong-gia-va-bien-ca.jpg',
            33 => 'gatsby-vi-dai.jpg',
            34 => 'Ebook-Kieu-hanh-va-dinh-kien.jpg',
            35 => 'oliver-twist.jpg',
            36 => 'charlie-va-nha-may-chocolate.jpg',
            37 => 'truyen-co-andersen.jpg',
            38 => 'truyen-co-grimm.jpg',
            40 => 'doraemon.jpg',
            42 => 'one-piece.jpg',
            43 => 'dac-nhan-tam.jpg',
            44 => 'cha-giau-cha-ngheo.jpg',
            45 => 'nghi-giau-va-lam-giau.jpg',
            46 => '7-thoi-quen-de-thanh-dat.jpg',
            47 => 'diem-bung-phat.jpg',
            48 => 'sapiens-luoc-su-loai-nguoi.jpg',
            49 => 'vu-tru.jpg',
            50 => 'luoc-su-thoi-gian.jpg',
        ];

        $updated = 0;
        foreach ($map as $id => $file) {
            if ($file === null) continue;
            DB::table('books')->where('book_id', $id)->update(['cover_image' => $base . $file]);
            $updated++;
        }

        $this->info("Updated $updated books with GitHub raw URLs.");
    }
}
