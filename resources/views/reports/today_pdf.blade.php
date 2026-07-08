<!DOCTYPE html>
<html lang="vi">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Báo cáo hoạt động hôm nay</title>
    <style>
        @page {
            margin: 20mm 15mm 25mm 15mm;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10px;
            color: #1a1a1a;
            background: #fff;
            line-height: 1.45;
        }

        /* ── Header ─────────────────────────────────────────────── */
        .header {
            text-align: center;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        .library-name {
            font-size: 15px;
            font-weight: bold;
            color: #2563eb;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .library-info {
            font-size: 9px;
            color: #6b7280;
            margin-top: 3px;
        }

        /* ── Tiêu đề báo cáo ─────────────────────────────────────── */
        .report-title {
            font-size: 14px;
            font-weight: bold;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #111;
            margin: 12px 0 4px;
        }
        .report-subtitle {
            font-size: 9px;
            text-align: center;
            color: #6b7280;
            margin-bottom: 12px;
        }

        /* ── Summary boxes (table format) ────────────────────────── */
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 18px;
        }
        .summary-table td {
            width: 33.33%;
            padding: 10px;
            text-align: center;
            border: 1px solid #e5e7eb;
        }
        .summary-box-borrow { background: #eff6ff; border-top: 3px solid #3b82f6 !important; }
        .summary-box-return { background: #f0fdf4; border-top: 3px solid #10b981 !important; }
        .summary-box-reserve { background: #fffbeb; border-top: 3px solid #f59e0b !important; }
        .summary-label { font-size: 9px; color: #4b5563; font-weight: bold; text-transform: uppercase; }
        .summary-value { font-size: 18px; font-weight: bold; margin-top: 4px; }
        .summary-value-borrow { color: #1d4ed8; }
        .summary-value-return { color: #047857; }
        .summary-value-reserve { color: #b45309; }

        /* ── Section Title ────────────────────────────────────────── */
        .section-title {
            font-size: 11px;
            font-weight: bold;
            color: #1e293b;
            background: #f1f5f9;
            padding: 4px 8px;
            border-left: 3px solid #2563eb;
            margin-top: 15px;
            margin-bottom: 6px;
            text-transform: uppercase;
        }

        /* ── Data table ─────────────────────────────────────────── */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
            margin-bottom: 12px;
        }
        .data-table th {
            background: #475569;
            color: #fff;
            font-weight: bold;
            padding: 6px 5px;
            text-align: left;
            border: 1px solid #334155;
            text-transform: uppercase;
            font-size: 8px;
        }
        .data-table td {
            padding: 5px 5px;
            vertical-align: middle;
            border: 1px solid #e5e7eb;
            line-height: 1.3;
        }
        .data-table tr:nth-child(even) td {
            background: #f8fafc;
        }
        .cell-stt { text-align: center; color: #6b7280; width: 24px; }
        .empty-text { text-align: center; color: #94a3b8; padding: 15px 0; font-style: italic; }

        /* ── Footer ──────────────────────────────────────────────── */
        .page-footer {
            position: fixed;
            bottom: -18mm;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8px;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
            padding-top: 4px;
        }
        .page-footer .page-num::before { content: "Trang "; }
        .page-footer .page-num { counter-increment: page; }
        .page-num::after { content: counter(page); }
        .page-total::before { content: " / "; }
        .page-total::after { content: counter(pages); }

        .badge {
            display: inline-block;
            padding: 1px 4px;
            font-size: 8px;
            font-weight: bold;
            border-radius: 3px;
        }
        .badge-blue { background: #dbeafe; color: #1e40af; }
        .badge-green { background: #dcfce7; color: #15803d; }
        .badge-orange { background: #ffedd5; color: #9a3412; }
        .badge-cyan { background: #ecfeff; color: #0891b2; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        .badge-gray { background: #f3f4f6; color: #374151; }

        tr { page-break-inside: avoid; }
    </style>
</head>
<body>

<div class="page-footer">
    <span class="page-num"></span><span class="page-total"></span>
    &nbsp;&nbsp;·&nbsp;&nbsp;
    Thư viện Quản lý Hệ thống
</div>

<div class="header">
    <div class="library-name">HỆ THỐNG QUẢN LÝ THƯ VIỆN</div>
    <div class="library-info">Báo cáo hoạt động vận hành trong ngày</div>
</div>

<div class="report-title">Báo cáo hoạt động hôm nay</div>
<div class="report-subtitle">Ngày báo cáo: {{ $today }}</div>

<!-- Summary Boxes -->
<table class="summary-table">
    <tr>
        <td class="summary-box-borrow">
            <div class="summary-label">Lượt mượn hôm nay</div>
            <div class="summary-value summary-value-borrow">{{ $summary['total_borrows'] }}</div>
        </td>
        <td class="summary-box-return">
            <div class="summary-label">Lượt trả hôm nay</div>
            <div class="summary-value summary-value-return">{{ $summary['total_returns'] }}</div>
        </td>
        <td class="summary-box-reserve">
            <div class="summary-label">Đặt trước hôm nay</div>
            <div class="summary-value summary-value-reserve">{{ $summary['total_reservations'] }}</div>
        </td>
    </tr>
</table>

<!-- Section 1: Borrows -->
<div class="section-title">Chi tiết lượt mượn hôm nay ({{ $summary['total_borrows'] }})</div>
<table class="data-table">
    <thead>
        <tr>
            <th style="width:24px">#</th>
            <th style="width:30%">Độc giả</th>
            <th style="width:45%">Sách mượn</th>
            <th style="width:12%; text-align:center">Hạn trả</th>
            <th style="width:13%; text-align:center">Trạng thái</th>
        </tr>
    </thead>
    <tbody>
        @if(count($details['borrows']) === 0)
            <tr>
                <td colspan="5" class="empty-text">Không có lượt mượn nào trong ngày hôm nay</td>
            </tr>
        @else
            @foreach($details['borrows'] as $i => $r)
                <tr>
                    <td class="cell-stt">{{ $i + 1 }}</td>
                    <td>
                        <strong>{{ $r['reader_name'] }}</strong><br/>
                        <span style="color:#6b7280; font-size:8px;">{{ $r['reader_email'] }}</span>
                    </td>
                    <td>{{ $r['books'] ?? '—' }}</td>
                    <td style="text-align:center">{{ \Carbon\Carbon::parse($r['due_date'])->format('d/m/Y') }}</td>
                    <td style="text-align:center">
                        <span class="badge {{ $r['status'] === 'borrowing' ? 'badge-blue' : 'badge-green' }}">
                            {{ $r['status'] === 'borrowing' ? 'Đang mượn' : 'Đã trả' }}
                        </span>
                    </td>
                </tr>
            @endforeach
        @endif
    </tbody>
</table>

<!-- Section 2: Returns -->
<div class="section-title">Chi tiết lượt trả hôm nay ({{ $summary['total_returns'] }})</div>
<table class="data-table">
    <thead>
        <tr>
            <th style="width:24px">#</th>
            <th style="width:30%">Độc giả</th>
            <th style="width:45%">Sách trả</th>
            <th style="width:12%; text-align:center">Giờ trả</th>
            <th style="width:13%; text-align:right">Tiền phạt</th>
        </tr>
    </thead>
    <tbody>
        @if(count($details['returns']) === 0)
            <tr>
                <td colspan="5" class="empty-text">Không có lượt trả nào trong ngày hôm nay</td>
            </tr>
        @else
            @foreach($details['returns'] as $i => $r)
                <tr>
                    <td class="cell-stt">{{ $i + 1 }}</td>
                    <td>
                        <strong>{{ $r['reader_name'] }}</strong><br/>
                        <span style="color:#6b7280; font-size:8px;">{{ $r['reader_email'] }}</span>
                    </td>
                    <td>{{ $r['book_title'] }}</td>
                    <td style="text-align:center">{{ \Carbon\Carbon::parse($r['return_date'])->format('H:i') }}</td>
                    <td style="text-align:right; font-weight:bold; color:{{ $r['fine_amount'] > 0 ? '#b91c1c' : '#6b7280' }}">
                        {{ $r['fine_amount'] > 0 ? number_format($r['fine_amount'], 0, ',', '.') . 'đ' : '—' }}
                    </td>
                </tr>
            @endforeach
        @endif
    </tbody>
</table>

<!-- Section 3: Reservations -->
<div class="section-title">Chi tiết đặt trước hôm nay ({{ $summary['total_reservations'] }})</div>
<table class="data-table">
    <thead>
        <tr>
            <th style="width:24px">#</th>
            <th style="width:30%">Độc giả</th>
            <th style="width:35%">Sách đặt</th>
            <th style="width:10%; text-align:center">Giờ đặt</th>
            <th style="width:10%; text-align:center">Hàng chờ</th>
            <th style="width:15%; text-align:center">Trạng thái</th>
        </tr>
    </thead>
    <tbody>
        @if(count($details['reservations']) === 0)
            <tr>
                <td colspan="6" class="empty-text">Không có lượt đặt trước nào trong ngày hôm nay</td>
            </tr>
        @else
            @foreach($details['reservations'] as $i => $r)
                @php
                    $badgeClass = match($r['status']) {
                        'waiting' => 'badge-orange',
                        'ready' => 'badge-cyan',
                        'completed' => 'badge-green',
                        'cancelled' => 'badge-red',
                        default => 'badge-gray'
                    };
                    $statusLabel = match($r['status']) {
                        'waiting' => 'Đang chờ',
                        'ready' => 'Sẵn sàng',
                        'completed' => 'Đã nhận',
                        'cancelled' => 'Đã hủy',
                        'expired' => 'Hết hạn',
                        default => $r['status']
                    };
                @endphp
                <tr>
                    <td class="cell-stt">{{ $i + 1 }}</td>
                    <td>
                        <strong>{{ $r['reader_name'] }}</strong><br/>
                        <span style="color:#6b7280; font-size:8px;">{{ $r['reader_email'] }}</span>
                    </td>
                    <td>{{ $r['book_title'] }}</td>
                    <td style="text-align:center">{{ \Carbon\Carbon::parse($r['created_at'])->format('H:i') }}</td>
                    <td style="text-align:center"><strong>{{ $r['queue_position'] }}</strong></td>
                    <td style="text-align:center">
                        <span class="badge {{ $badgeClass }}">{{ $statusLabel }}</span>
                    </td>
                </tr>
            @endforeach
        @endif
    </tbody>
</table>

</body>
</html>
