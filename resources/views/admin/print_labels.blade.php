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
            font-size: 12px;
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
    <div class="grid-container">
        @foreach($copies as $copy)
            <div class="label-card">
                <div class="qr-section">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data={{ urlencode($copy->barcode) }}" alt="QR">
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
