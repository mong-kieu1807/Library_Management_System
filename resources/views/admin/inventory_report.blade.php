<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Báo cáo tình trạng kho sách - Thư viện</title>
    <style>
        @page {
            size: A4 portrait;
            margin: 15mm 15mm 20mm 15mm;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            color: #333;
            line-height: 1.5;
            background-color: #fff;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #2563EB;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        .header h1 {
            font-size: 22px;
            color: #1E3A8A;
            margin: 0 0 5px 0;
            text-transform: uppercase;
        }
        .header p {
            margin: 0;
            font-size: 13px;
            color: #666;
        }
        .meta-info {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: #555;
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        .section-title {
            font-size: 15px;
            font-weight: bold;
            color: #1E3A8A;
            margin: 20px 0 10px 0;
            border-left: 4px solid #2563EB;
            padding-left: 8px;
        }
        .grid-summary {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 20px;
        }
        .summary-card {
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 12px;
            text-align: center;
            background-color: #f9fafb;
        }
        .summary-card .number {
            font-size: 20px;
            font-weight: bold;
            color: #2563EB;
            margin-top: 5px;
        }
        .summary-card .label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #e5e7eb;
            padding: 8px 10px;
            text-align: left;
        }
        th {
            background-color: #f3f4f6;
            color: #111;
            font-weight: bold;
        }
        tr:nth-child(even) td {
            background-color: #fafafa;
        }
        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: bold;
        }
        .badge-success { background-color: #d1fae5; color: #065f46; }
        .badge-info { background-color: #dbeafe; color: #1e40af; }
        .badge-warning { background-color: #fef3c7; color: #92400e; }
        .badge-danger { background-color: #fee2e2; color: #991b1b; }
        .badge-secondary { background-color: #f3f4f6; color: #374151; }
        
        .footer-sig {
            margin-top: 50px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            text-align: center;
            font-size: 13px;
        }
        .footer-sig div p {
            margin: 0;
        }
        .footer-sig .signature-space {
            height: 70px;
        }
        @media print {
            body {
                background: none;
            }
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Báo cáo Tổng hợp tình trạng kho sách @if(isset($categoryName)) - Thể loại: {{ $categoryName }} @endif</h1>
        <p>Hệ thống Quản lý Thư viện ABC</p>
    </div>

    <div class="meta-info">
        <div>Ngày lập báo cáo: {{ date('d/m/Y H:i') }}</div>
        <div>Người lập: Ban quản trị Thư viện</div>
    </div>

    <div class="section-title">Số liệu tổng quan kho sách</div>
    <div class="grid-summary">
        <div class="summary-card">
            <div class="label">Tổng số bản sao sách</div>
            <div class="number">{{ $total }}</div>
        </div>
        <div class="summary-card">
            <div class="label">Sách có sẵn phục vụ</div>
            <div class="number" style="color: #059669;">{{ $stats['available'] }}</div>
        </div>
        <div class="summary-card">
            <div class="label">Sách đang cho mượn</div>
            <div class="number" style="color: #2563EB;">{{ $stats['borrowed'] }}</div>
        </div>
    </div>

    <div class="section-title">Thống kê theo trạng thái bản sao</div>
    <table>
        <thead>
            <tr>
                <th>Trạng thái</th>
                <th>Mô tả trạng thái</th>
                <th style="text-align: right;">Số lượng (Quyển)</th>
                <th style="text-align: right;">Tỷ lệ (%)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><span class="badge badge-success">Có sẵn</span></td>
                <td>Sẵn sàng trên kệ để phục vụ độc giả</td>
                <td style="text-align: right;">{{ $stats['available'] }}</td>
                <td style="text-align: right;">{{ $total > 0 ? round(($stats['available'] / $total) * 100, 1) : 0 }}%</td>
            </tr>
            <tr>
                <td><span class="badge badge-info">Đang mượn</span></td>
                <td>Độc giả đang mượn đọc ngoài thư viện</td>
                <td style="text-align: right;">{{ $stats['borrowed'] }}</td>
                <td style="text-align: right;">{{ $total > 0 ? round(($stats['borrowed'] / $total) * 100, 1) : 0 }}%</td>
            </tr>
            <tr>
                <td><span class="badge badge-warning">Đặt trước</span></td>
                <td>Đang giữ chỗ chờ độc giả đến nhận</td>
                <td style="text-align: right;">{{ $stats['reserved'] }}</td>
                <td style="text-align: right;">{{ $total > 0 ? round(($stats['reserved'] / $total) * 100, 1) : 0 }}%</td>
            </tr>
            <tr>
                <td><span class="badge badge-secondary" style="background-color: #f3e8ff; color: #6b21a8;">Bảo trì</span></td>
                <td>Đang bọc bìa, khâu gáy hoặc sửa chữa phục hồi</td>
                <td style="text-align: right;">{{ $stats['maintenance'] }}</td>
                <td style="text-align: right;">{{ $total > 0 ? round(($stats['maintenance'] / $total) * 100, 1) : 0 }}%</td>
            </tr>
            <tr>
                <td><span class="badge badge-danger">Mất/Hỏng</span></td>
                <td>Quyển sách bị mất hoặc bị hư hại chưa thanh lý</td>
                <td style="text-align: right;">{{ $stats['lost'] }}</td>
                <td style="text-align: right;">{{ $total > 0 ? round(($stats['lost'] / $total) * 100, 1) : 0 }}%</td>
            </tr>
            <tr>
                <td><span class="badge badge-secondary">Đã thanh lý</span></td>
                <td>Sách đã thanh lý khỏi thư viện (Lưu trữ lịch sử)</td>
                <td style="text-align: right;">{{ $stats['liquidated'] }}</td>
                <td style="text-align: right;">{{ $total > 0 ? round(($stats['liquidated'] / $total) * 100, 1) : 0 }}%</td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">Thống kê cơ cấu tình trạng vật lý</div>
    <table>
        <thead>
            <tr>
                <th>Tình trạng vật lý</th>
                <th style="text-align: right;">Số lượng (Quyển)</th>
                <th style="text-align: right;">Tỷ lệ (%)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Mới (New)</td>
                <td style="text-align: right;">{{ $conditions['new'] }}</td>
                <td style="text-align: right;">{{ $total > 0 ? round(($conditions['new'] / $total) * 100, 1) : 0 }}%</td>
            </tr>
            <tr>
                <td>Tốt (Good)</td>
                <td style="text-align: right;">{{ $conditions['good'] }}</td>
                <td style="text-align: right;">{{ $total > 0 ? round(($conditions['good'] / $total) * 100, 1) : 0 }}%</td>
            </tr>
            <tr>
                <td>Cũ (Old)</td>
                <td style="text-align: right;">{{ $conditions['old'] }}</td>
                <td style="text-align: right;">{{ $total > 0 ? round(($conditions['old'] / $total) * 100, 1) : 0 }}%</td>
            </tr>
            <tr>
                <td>Hỏng nhẹ (Light damage)</td>
                <td style="text-align: right;">{{ $conditions['light'] }}</td>
                <td style="text-align: right;">{{ $total > 0 ? round(($conditions['light'] / $total) * 100, 1) : 0 }}%</td>
            </tr>
            <tr>
                <td>Hỏng nặng (Heavy damage)</td>
                <td style="text-align: right;">{{ $conditions['heavy'] }}</td>
                <td style="text-align: right;">{{ $total > 0 ? round(($conditions['heavy'] / $total) * 100, 1) : 0 }}%</td>
            </tr>
        </tbody>
    </table>

    <div class="section-title">Danh sách bản sao nhập kho mới nhất</div>
    <table>
        <thead>
            <tr>
                <th>Mã bản sao</th>
                <th>Tên đầu sách</th>
                <th>Barcode</th>
                <th>Vị trí kệ</th>
                <th>Tình trạng</th>
                <th>Trạng thái</th>
            </tr>
        </thead>
        <tbody>
            @foreach($recentCopies as $copy)
                <tr>
                    <td>{{ $copy->copy_id }}</td>
                    <td>{{ $copy->book ? $copy->book->title : 'N/A' }}</td>
                    <td style="font-family: monospace;">{{ $copy->barcode }}</td>
                    <td>{{ $copy->shelf_location ?: 'Chưa xếp' }}</td>
                    <td>{{ $copy->condition }}</td>
                    <td>{{ $copy->status }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer-sig">
        <div>
            <p>Thủ thư kiểm kho</p>
            <p class="signature-space">(Ký và ghi rõ họ tên)</p>
        </div>
        <div>
            <p>Hà Nội, Ngày ..... tháng ..... năm 2026</p>
            <p style="font-weight: bold; margin-top: 5px;">Xác nhận của Giám đốc Thư viện</p>
            <p class="signature-space">(Ký tên và đóng dấu)</p>
        </div>
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
