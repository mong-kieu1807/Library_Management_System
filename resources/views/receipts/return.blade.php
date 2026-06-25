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
        .library-name {
            font-size: 16px;
            font-weight: bold;
            color: #dc2626;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .library-info {
            font-size: 9px;
            color: #6b7280;
            margin-top: 3px;
        }
        .receipt-title {
            font-size: 13px;
            font-weight: bold;
            text-align: center;
            margin: 14px 0 4px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #111;
        }
        .receipt-id {
            text-align: center;
            font-size: 10px;
            color: #6b7280;
            margin-bottom: 16px;
        }
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
        .info-block { margin-bottom: 14px; }
        .info-row { display: flex; margin-bottom: 4px; }
        .info-label { width: 110px; color: #6b7280; font-size: 10px; }
        .info-value { flex: 1; font-size: 10px; font-weight: 600; color: #111; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        th {
            background: #dc2626;
            color: #fff;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            padding: 6px 8px;
            text-align: left;
        }
        td {
            padding: 6px 8px;
            font-size: 10px;
            border-bottom: 1px solid #f3f4f6;
            vertical-align: top;
        }
        tr:last-child td { border-bottom: none; }
        tr:nth-child(even) td { background: #fafafa; }
        .barcode-cell {
            font-family: monospace;
            font-size: 9px;
            background: #f3f4f6;
            padding: 2px 5px;
            border-radius: 3px;
        }
        .overdue-cell { color: #dc2626; font-weight: bold; }
        .fee-cell { font-weight: bold; }
        .fee-positive { color: #dc2626; }
        .fee-zero { color: #9ca3af; }
        .total-box {
            background: #fff7ed;
            border: 1.5px solid #fdba74;
            border-radius: 6px;
            padding: 10px 14px;
            margin-bottom: 14px;
        }
        .total-label {
            font-size: 9px;
            color: #ea580c;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: bold;
        }
        .total-value {
            font-size: 16px;
            font-weight: bold;
            color: #dc2626;
            margin-top: 3px;
        }
        .total-value-zero {
            font-size: 16px;
            font-weight: bold;
            color: #16a34a;
            margin-top: 3px;
        }
        .payment-status {
            display: inline-block;
            font-size: 9px;
            font-weight: bold;
            padding: 2px 7px;
            border-radius: 4px;
            margin-top: 4px;
        }
        .status-paid { background: #dcfce7; color: #16a34a; }
        .status-unpaid { background: #fee2e2; color: #dc2626; }
        .footer {
            margin-top: 20px;
            padding-top: 12px;
            border-top: 1px solid #e5e7eb;
        }
        .signature-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
        }
        .signature-box {
            width: 45%;
            text-align: center;
        }
        .signature-title {
            font-size: 9px;
            text-transform: uppercase;
            font-weight: bold;
            letter-spacing: 0.3px;
            color: #6b7280;
            margin-bottom: 24px;
        }
        .signature-name {
            font-size: 10px;
            font-weight: 600;
            border-top: 1px solid #9ca3af;
            padding-top: 4px;
        }
        .note {
            font-size: 8.5px;
            color: #9ca3af;
            text-align: center;
            margin-top: 12px;
            font-style: italic;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="library-name">{{ $libraryName }}</div>
    <div class="library-info">{{ $settings['address'] ?? '' }}  ·  ĐT: {{ $settings['contact_phone'] ?? '' }}</div>
</div>

<div class="receipt-title">Biên lai trả sách</div>
<div class="receipt-id">Mã phiếu: #{{ str_pad($borrow->borrow_id, 6, '0', STR_PAD_LEFT) }}  ·  Ngày in: {{ now()->format('d/m/Y H:i') }}</div>

{{-- Reader info --}}
<div class="info-block">
    <div class="section-title">Thông tin độc giả</div>
    <div class="info-row">
        <span class="info-label">Họ và tên</span>
        <span class="info-value">{{ $reader->full_name }}</span>
    </div>
    <div class="info-row">
        <span class="info-label">Mã thẻ thư viện</span>
        <span class="info-value">{{ $reader->card_number ?? '—' }}</span>
    </div>
</div>

{{-- Borrow info --}}
<div class="info-block">
    <div class="section-title">Thông tin phiếu mượn</div>
    <div class="info-row">
        <span class="info-label">Ngày mượn</span>
        <span class="info-value">{{ \Carbon\Carbon::parse($borrow->borrow_date)->format('d/m/Y') }}</span>
    </div>
    <div class="info-row">
        <span class="info-label">Hạn trả gốc</span>
        <span class="info-value">{{ \Carbon\Carbon::parse($borrow->due_date)->format('d/m/Y') }}</span>
    </div>
    <div class="info-row">
        <span class="info-label">Ngày trả thực tế</span>
        <span class="info-value">{{ $returnDate }}</span>
    </div>
</div>

{{-- Returned books table --}}
<div class="section-title">Sách đã trả ({{ count($returnedBooks) }} cuốn)</div>
<table>
    <thead>
        <tr>
            <th style="width:30px">#</th>
            <th style="width:90px">Barcode</th>
            <th>Tên sách</th>
            <th style="width:55px; text-align:right">Quá hạn</th>
            <th style="width:90px; text-align:right">Phí phạt</th>
        </tr>
    </thead>
    <tbody>
        @foreach($returnedBooks as $i => $book)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td><span class="barcode-cell">{{ $book->barcode }}</span></td>
            <td>{{ $book->title }}</td>
            <td style="text-align:right" class="{{ $book->overdue_days > 0 ? 'overdue-cell' : 'fee-zero' }}">
                {{ $book->overdue_days > 0 ? $book->overdue_days . ' ngày' : '—' }}
            </td>
            <td style="text-align:right" class="{{ $book->fine_amount > 0 ? 'fee-positive' : 'fee-zero' }}">
                {{ $book->fine_amount > 0 ? number_format($book->fine_amount, 0, ',', '.') . ' ₫' : '—' }}
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

{{-- Total penalty --}}
<div class="total-box">
    <div class="total-label">Tổng phí phạt</div>
    @if($totalPenalty > 0)
        <div class="total-value">{{ number_format($totalPenalty, 0, ',', '.') }} VNĐ</div>
        @php
            $allPaid = collect($returnedBooks)->every(fn($b) => ($b->fine_status ?? '') === 'paid');
            $anyUnpaid = collect($returnedBooks)->contains(fn($b) => ($b->fine_status ?? '') === 'unpaid');
        @endphp
        @if($anyUnpaid)
            <span class="payment-status status-unpaid">Chưa thanh toán</span>
        @else
            <span class="payment-status status-paid">Đã thanh toán</span>
        @endif
    @else
        <div class="total-value-zero">0 VNĐ — Không có phí phạt</div>
    @endif
</div>

{{-- Signatures --}}
<div class="footer">
    <div class="signature-row">
        <div class="signature-box">
            <div class="signature-title">Độc giả xác nhận<br>(Ký, ghi rõ họ tên)</div>
            <div class="signature-name">{{ $reader->full_name }}</div>
        </div>
        <div class="signature-box">
            <div class="signature-title">Thủ thư xác nhận<br>(Ký, ghi rõ họ tên)</div>
            <div class="signature-name">{{ $librarian ?? 'Thủ thư' }}</div>
        </div>
    </div>
    <div class="note">
        Biên lai này là bằng chứng giao dịch trả sách. Vui lòng lưu giữ để đối chiếu khi cần.
    </div>
</div>

</body>
</html>
