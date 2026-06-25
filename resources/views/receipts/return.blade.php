<!DOCTYPE html>
<html lang="vi">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Biên lai trả sách #{{ $borrow->borrow_id }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 11px;
            color: #1a1a1a;
            padding: 30px 40px;
            background: #fff;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #dc2626;
            padding-bottom: 14px;
            margin-bottom: 18px;
        }
        .library-name { font-size: 16px; font-weight: bold; color: #dc2626; text-transform: uppercase; letter-spacing: 1px; }
        .library-info { font-size: 9px; color: #6b7280; margin-top: 3px; }
        .receipt-title {
            font-size: 13px;
            font-weight: bold;
            text-align: center;
            margin: 14px 0 4px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #111;
        }
        .receipt-id { text-align: center; font-size: 10px; color: #6b7280; margin-bottom: 16px; }
        /* 2-column info grid */
        .info-grid { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .info-grid td { width: 50%; vertical-align: top; padding-right: 12px; border: none; background: transparent; }
        .info-grid td:last-child { padding-right: 0; padding-left: 12px; }
        .section-title {
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b7280;
            margin-bottom: 6px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 3px;
        }
        .info-row { display: flex; margin-bottom: 5px; }
        .info-label { width: 110px; color: #6b7280; font-size: 10px; flex-shrink: 0; }
        .info-value { flex: 1; font-size: 10px; font-weight: 600; color: #111; }
        /* Books table */
        .books-section { margin-bottom: 14px; }
        table { width: 100%; border-collapse: collapse; }
        th {
            background: #dc2626;
            color: #fff;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            padding: 6px 8px;
            text-align: left;
        }
        td { padding: 6px 8px; font-size: 10px; border-bottom: 1px solid #f3f4f6; vertical-align: top; background: transparent; }
        tr:last-child td { border-bottom: none; }
        tr:nth-child(even) td { background: #fafafa; }
        .barcode-cell { font-family: monospace; font-size: 9px; background: #f3f4f6; padding: 2px 5px; border-radius: 3px; display: inline-block; }
        .overdue-text { color: #dc2626; font-weight: bold; }
        .ok-text { color: #9ca3af; }
        /* Fee box */
        .fee-box {
            border: 1.5px solid #fdba74;
            border-radius: 6px;
            padding: 12px 14px;
            margin-bottom: 14px;
            background: #fff7ed;
        }
        .fee-box-title { font-size: 9px; color: #ea580c; text-transform: uppercase; letter-spacing: 0.5px; font-weight: bold; margin-bottom: 8px; }
        .fee-row { display: flex; justify-content: space-between; margin-bottom: 5px; font-size: 10px; }
        .fee-row-label { color: #374151; }
        .fee-row-value { font-weight: 700; }
        .fee-total { font-size: 12px; font-weight: bold; border-bottom: 1px solid #fdba74; padding-bottom: 6px; margin-bottom: 6px; }
        .fee-paid-value { color: #16a34a; }
        .fee-unpaid-value { color: #dc2626; }
        .fee-zero-value { color: #9ca3af; }
        .fee-none-box { font-size: 13px; font-weight: bold; color: #16a34a; }
        /* Footer */
        .footer { margin-top: 20px; padding-top: 12px; border-top: 1px solid #e5e7eb; }
        .sig-table { width: 100%; border-collapse: collapse; }
        .sig-table td { width: 50%; text-align: center; border: none; background: transparent; padding: 0; }
        .signature-title { font-size: 9px; text-transform: uppercase; font-weight: bold; letter-spacing: 0.3px; color: #6b7280; margin-bottom: 28px; }
        .signature-name { font-size: 10px; font-weight: 600; border-top: 1px solid #9ca3af; padding-top: 4px; margin: 0 20px; }
        .footer-note { font-size: 9px; color: #374151; text-align: center; margin-top: 14px; font-style: italic; font-weight: 600; }
    </style>
</head>
<body>

<div class="header">
    <div class="library-name">{{ $libraryName }}</div>
    <div class="library-info">{{ $settings['address'] ?? '' }}@if(!empty($settings['contact_phone']))  ·  ĐT: {{ $settings['contact_phone'] }}@endif</div>
</div>

<div class="receipt-title">Biên lai trả sách</div>
<div class="receipt-id">Mã phiếu: #{{ str_pad($borrow->borrow_id, 6, '0', STR_PAD_LEFT) }}&nbsp;&nbsp;·&nbsp;&nbsp;Ngày in: {{ now()->format('d/m/Y H:i') }}</div>

{{-- 2-column info --}}
<table class="info-grid">
    <tr>
        <td>
            <div class="section-title">Thông tin độc giả</div>
            <div class="info-row"><span class="info-label">Họ và tên</span><span class="info-value">{{ $reader->full_name }}</span></div>
            <div class="info-row"><span class="info-label">Mã thẻ thư viện</span><span class="info-value">{{ $reader->card_number ?? '—' }}</span></div>
        </td>
        <td>
            <div class="section-title">Thông tin trả sách</div>
            <div class="info-row"><span class="info-label">Mã phiếu mượn</span><span class="info-value">#{{ str_pad($borrow->borrow_id, 6, '0', STR_PAD_LEFT) }}</span></div>
            <div class="info-row"><span class="info-label">Ngày mượn</span><span class="info-value">{{ \Carbon\Carbon::parse($borrow->borrow_date)->format('d/m/Y') }}</span></div>
            <div class="info-row"><span class="info-label">Hạn trả gốc</span><span class="info-value">{{ \Carbon\Carbon::parse($borrow->due_date)->format('d/m/Y') }}</span></div>
            <div class="info-row"><span class="info-label">Ngày trả thực tế</span><span class="info-value" style="color:#dc2626;font-weight:bold">{{ $returnDate }}</span></div>
            <div class="info-row"><span class="info-label">Thủ thư xử lý</span><span class="info-value">{{ $librarian }}</span></div>
        </td>
    </tr>
</table>

{{-- Books table: BARCODE | TÊN SÁCH | SỐ NGÀY TRỄ (no per-copy fee) --}}
<div class="books-section">
    <div class="section-title">Sách đã trả ({{ count($returnedBooks) }} cuốn)</div>
    <table>
        <thead>
            <tr>
                <th style="width:30px">#</th>
                <th style="width:110px">Barcode</th>
                <th>Tên sách</th>
                <th style="width:80px; text-align:right">Số ngày trễ</th>
            </tr>
        </thead>
        <tbody>
            @foreach($returnedBooks as $i => $book)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td><span class="barcode-cell">{{ $book->barcode }}</span></td>
                <td>{{ $book->title }}</td>
                <td style="text-align:right" class="{{ $book->overdue_days > 0 ? 'overdue-text' : 'ok-text' }}">
                    {{ $book->overdue_days > 0 ? $book->overdue_days . ' ngày' : '—' }}
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>

{{-- Fee box: Tổng phí | Đã thu | Chưa thu --}}
<div class="fee-box">
    <div class="fee-box-title">Thông tin phí phạt</div>
    @if($totalFine > 0)
        <div class="fee-row fee-total">
            <span class="fee-row-label">Tổng phí phạt</span>
            <span class="fee-row-value" style="color:#dc2626">{{ number_format($totalFine, 0, ',', '.') }} VNĐ</span>
        </div>
        <div class="fee-row">
            <span class="fee-row-label">Đã thu</span>
            <span class="fee-row-value {{ $paidAmount > 0 ? 'fee-paid-value' : 'fee-zero-value' }}">
                {{ $paidAmount > 0 ? number_format($paidAmount, 0, ',', '.') . ' VNĐ' : '0 VNĐ' }}
            </span>
        </div>
        <div class="fee-row">
            <span class="fee-row-label">Chưa thu</span>
            <span class="fee-row-value {{ $unpaidAmount > 0 ? 'fee-unpaid-value' : 'fee-zero-value' }}">
                {{ $unpaidAmount > 0 ? number_format($unpaidAmount, 0, ',', '.') . ' VNĐ' : '0 VNĐ' }}
            </span>
        </div>
    @else
        <div class="fee-none-box">Không có phí phạt</div>
    @endif
</div>

<div class="footer">
    <table class="sig-table">
        <tr>
            <td>
                <div class="signature-title">Độc giả xác nhận<br>(Ký, ghi rõ họ tên)</div>
                <div class="signature-name">{{ $reader->full_name }}</div>
            </td>
            <td>
                <div class="signature-title">Thủ thư xác nhận<br>(Ký, ghi rõ họ tên)</div>
                <div class="signature-name">{{ $librarian }}</div>
            </td>
        </tr>
    </table>
    <div class="footer-note">"Xác nhận đã nhận lại sách."</div>
</div>

</body>
</html>
