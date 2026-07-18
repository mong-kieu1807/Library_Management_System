<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>In Nhãn Bản Sao Sách - Khổ A4</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #fff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .grid-container {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            grid-auto-rows: 80px;
            gap: 12px 10px;
        }
        .label-card {
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 8px;
            display: flex;
            align-items: center;
            box-sizing: border-box;
            background: #fff;
            overflow: hidden;
        }
        .qr-section {
            flex: 0 0 64px;
            margin-right: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-right: 1px dashed #eee;
            padding-right: 8px;
        }
        .qr-section img {
            width: 56px;
            height: 56px;
            display: block;
        }
        .info-section {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
            justify-content: center;
            text-align: left;
        }
        .book-title {
            font-size: 11px;
            font-weight: 700;
            color: #333;
            margin: 0 0 3px 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .barcode-text {
            font-family: 'Courier New', Courier, monospace;
            font-size: 11px;
            font-weight: 700;
            color: #000;
            margin: 0;
            letter-spacing: 0.5px;
        }
        .shelf-loc {
            font-size: 10px;
            color: #555;
            margin: 3px 0 0 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        @media print {
            body {
                background: none;
            }
            .label-card {
                page-break-inside: avoid;
                border-color: #999;
            }
        }
    </style>
</head>
<body>
    @php
        if (!function_exists('removeVietnameseTones')) {
            function removeVietnameseTones($str) {
                $unicode = array(
                    'a'=>'á|à|ả|ã|ạ|ă|ắ|ằ|ẳ|ẵ|ặ|â|ấ|ầ|ẩ|ẫ|ậ',
                    'd'=>'đ',
                    'e'=>'é|è|ẻ|ẽ|ẹ|ê|ế|ề|ể|ễ|ệ',
                    'i'=>'í|ì|ỉ|ĩ|ị',
                    'o'=>'ó|ò|ỏ|õ|ọ|ô|ố|ồ|ổ|ỗ|ộ|ơ|ớ|ờ|ở|ỡ|ợ',
                    'u'=>'ú|ù|ủ|ũ|ụ|ư|ứ|ừ|ử|ữ|ự',
                    'y'=>'ý|ỳ|ỷ|ỹ|ỵ',
                    'A'=>'Á|À|Ả|Ã|Ạ|Ă|Ắ|Ằ|Ẳ|Ẵ|Ặ|Â|Ấ|Ầ|Ẩ|Ẫ|Ậ',
                    'D'=>'Đ',
                    'E'=>'É|È|Ẻ|Ẽ|Ẹ|Ê|Ế|Ề|Ể|Ễ|Ệ',
                    'I'=>'Í|Ì|Ỉ|Ĩ|Ị',
                    'O'=>'Ó|Ò|Ỏ|Õ|Ọ|Ô|Ố|Ồ|Ổ|Ỗ|Ộ|Ơ|Ớ|Ờ|Ở|Ỡ|Ợ',
                    'U'=>'Ú|Ù|Ủ|Ũ|Ụ|Ư|Ứ|Ừ|Ử|Ữ|Ự',
                    'Y'=>'Ý|Ỳ|Ỷ|Ỹ|Ỵ',
                );
                foreach($unicode as $nonUnicode=>$uni){
                    $str = preg_replace("/($uni)/i", $nonUnicode, $str);
                }
                return $str;
            }
        }
    @endphp
    <div class="grid-container">
        @foreach($copies as $copy)
            @php
                $book = $copy->book;
                $publisher = $book && $book->publisher ? $book->publisher->publisher_name : 'N/A';
                $cost = $book ? number_format($book->replacement_cost, 0, ',', '.') . ' đ' : '0 đ';
                $rating = $book ? ($book->avg_rating ?: '0.0') . '/5 (' . ($book->total_reviews ?: 0) . ' lượt)' : '0.0/5 (0 lượt)';

                $rawQrData = "[THONG TIN SACH]\n"
                        . "=====================\n"
                        . "Ma ban sao: " . ($copy->barcode) . "\n"
                        . "Ma sach: " . ($book ? $book->book_id : 'N/A') . "\n"
                        . "ISBN: " . ($book ? $book->isbn : 'N/A') . "\n"
                        . "Ke: " . ($copy->shelf_location ?: 'Chua xep ke') . "\n"
                        . "---------------------\n"
                        . "Ten sach: " . ($book ? $book->title : 'Chua ro') . "\n"
                        . "Nha xuat ban: " . $publisher . "\n"
                        . "Ngay xuat ban: " . ($book && $book->publish_year ? $book->publish_year : 'Chua cap nhat') . "\n"
                        . "Phien ban: " . ($book && $book->edition ? $book->edition : 'Chua cap nhat') . "\n"
                        . "Ngon ngu: " . ($book && $book->language ? mb_strtoupper($book->language) : 'TIENG VIET') . "\n"
                        . "So trang: " . ($book && $book->pages ? $book->pages . ' trang' : 'Chua cap nhat') . "\n"
                        . "Kich thuoc: " . ($book && $book->dimensions ? $book->dimensions : 'Chua cap nhat') . "\n"
                        . "Loai bia: " . ($book && $book->cover_type ? $book->cover_type : 'Chua cap nhat') . "\n"
                        . "Gia den bu: " . $cost . "\n"
                        . "Danh gia: " . $rating . "\n"
                        . "---------------------\n"
                        . "Mo ta:\n" . ($book && $book->description ? $book->description : 'Khong co mo ta') . "\n"
                        . "=====================";
                
                $qrData = removeVietnameseTones($rawQrData);
            @endphp
            <div class="label-card">
                <div class="qr-section">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&charset-target=UTF-8&data={{ urlencode($qrData) }}" alt="QR">
                </div>
                <div class="info-section">
                    <p class="book-title">{{ $copy->book ? $copy->book->title : 'Chưa rõ sách' }}</p>
                    <p class="barcode-text">{{ $copy->barcode }}</p>
                    <p class="shelf-loc">Kệ: {{ $copy->shelf_location ?: 'Chưa xếp kệ' }}</p>
                </div>
            </div>
        @endforeach
    </div>
    <script>
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 600);
        };
    </script>
</body>
</html>
