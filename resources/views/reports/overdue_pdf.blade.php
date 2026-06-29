<!DOCTYPE html>
<html lang="vi">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Báo cáo sách quá hạn</title>
    <style>
        /*
         * QUAN TRỌNG — ràng buộc DomPDF:
         *  1. Font nhiều từ PHẢI có dấu nháy đơn: 'DejaVu Sans'
         *  2. KHÔNG dùng display:flex hay display:grid
         *  3. KHÔNG dùng calc() hay CSS variable
         *  4. Dùng <table> cho mọi layout nhiều cột
         *  5. @page chỉ hỗ trợ margin — KHÔNG dùng size (DomPDF bỏ qua)
         */

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
            border-bottom: 2px solid #dc2626;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        .library-name {
            font-size: 15px;
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

        /* ── Meta info (dùng table thay flex) ───────────────────── */
        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
        }
        .meta-table td {
            padding: 5px 10px;
            font-size: 9px;
            vertical-align: top;
            width: 50%;
        }
        .meta-label {
            color: #6b7280;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .meta-value {
            color: #111;
            font-weight: bold;
        }

        /* ── Summary row ────────────────────────────────────────── */
        .summary-row {
            background: #fef2f2;
            border: 1px solid #fecaca;
            padding: 6px 10px;
            margin-bottom: 12px;
            font-size: 10px;
        }
        .summary-label { color: #6b7280; }
        .summary-value { font-weight: bold; color: #dc2626; }

        /* ── Data table ─────────────────────────────────────────── */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }
        .data-table thead tr {
            background: #dc2626;
        }
        .data-table th {
            color: #fff;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 6px 5px;
            text-align: left;
            vertical-align: middle;
            border: 1px solid #b91c1c;
        }
        .data-table td {
            padding: 5px 5px;
            vertical-align: top;
            border: 1px solid #e5e7eb;
            line-height: 1.4;
        }
        .data-table tr:nth-child(even) td {
            background: #fef9f9;
        }
        .data-table tr:nth-child(odd) td {
            background: #ffffff;
        }

        /* ── Cell styles ──────────────────────────────────────────── */
        .cell-stt {
            text-align: center;
            color: #6b7280;
            width: 24px;
        }
        .cell-reader-name { font-weight: bold; }
        .cell-reader-email { font-size: 8px; color: #6b7280; margin-top: 2px; }
        .cell-book { font-weight: 500; }
        .cell-date { text-align: center; white-space: nowrap; }
        .cell-days { text-align: center; white-space: nowrap; }
        .cell-days-num { font-weight: bold; }
        .cell-fine { text-align: right; white-space: nowrap; font-weight: bold; }
        .cell-fine-zero { text-align: right; color: #9ca3af; }

        /* ── Mức độ label ─────────────────────────────────────────── */
        .badge {
            display: inline-block;
            padding: 1px 5px;
            font-size: 8px;
            font-weight: bold;
            border-radius: 3px;
            margin-top: 2px;
        }
        .badge-low    { background: #fef9c3; color: #854d0e; border: 1px solid #fde047; }
        .badge-medium { background: #ffedd5; color: #9a3412; border: 1px solid #fb923c; }
        .badge-high   { background: #fee2e2; color: #991b1b; border: 1px solid #f87171; }

        /* ── Footer số trang (DomPDF counter) ──────────────────────── */
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
        .page-footer .page-num {
            counter-increment: page;
        }
        /* DomPDF built-in page counter */
        .page-num::after { content: counter(page); }
        .page-total::before { content: " / "; }
        .page-total::after { content: counter(pages); }

        /* Không cắt hàng giữa trang */
        .data-table tr { page-break-inside: avoid; }
    </style>
</head>
<body>

{{-- Footer số trang — đặt trước body content để DomPDF render đúng position:fixed --}}
<div class="page-footer">
    <span class="page-num"></span><span class="page-total"></span>
    &nbsp;&nbsp;·&nbsp;&nbsp;
    Xuất lúc: {{ $generatedAt }}
</div>

{{-- ── Header ────────────────────────────────────────────────────────── --}}
<div class="header">
    <div class="library-name">{{ $libraryName }}</div>
    <div class="library-info">
        @if($address){{ $address }}@endif
        @if($address && $phone) &nbsp;·&nbsp; @endif
        @if($phone)ĐT: {{ $phone }}@endif
    </div>
</div>

{{-- ── Tiêu đề ──────────────────────────────────────────────────────── --}}
<div class="report-title">Báo cáo sách quá hạn</div>
<div class="report-subtitle">{{ $statusLabel }}</div>

{{-- ── Meta info ────────────────────────────────────────────────────── --}}
<table class="meta-table">
    <tr>
        <td>
            <span class="meta-label">Thời gian xuất</span><br/>
            <span class="meta-value">{{ $generatedAt }}</span>
        </td>
        <td>
            <span class="meta-label">Khoảng thời gian (hạn trả)</span><br/>
            <span class="meta-value">
                @if($toLabel)
                    {{ $fromLabel }} – {{ $toLabel }}
                @else
                    {{ $fromLabel }}
                @endif
            </span>
        </td>
    </tr>
</table>

{{-- ── Summary ──────────────────────────────────────────────────────── --}}
<div class="summary-row">
    <span class="summary-label">Tổng số bản sao quá hạn: </span>
    <span class="summary-value">{{ $total }} bản sao</span>
</div>

{{-- ── Data table ───────────────────────────────────────────────────── --}}
@if($total === 0)
    <p style="text-align:center; color:#6b7280; margin-top:30px; font-size:11px;">
        Không có sách quá hạn trong khoảng thời gian đã chọn.
    </p>
@else
<table class="data-table">
    <thead>
        <tr>
            <th style="width:24px">#</th>
            <th style="width:22%">Độc giả</th>
            <th style="width:28%">Tên sách</th>
            <th style="width:68px; text-align:center">Hạn trả</th>
            <th style="width:72px; text-align:center">Quá hạn</th>
            <th style="width:72px; text-align:right">Tiền phạt</th>
        </tr>
    </thead>
    <tbody>
        @foreach($items as $i => $row)
        <tr>
            <td class="cell-stt">{{ $i + 1 }}</td>
            <td>
                <div class="cell-reader-name">{{ $row['reader_name'] }}</div>
                <div class="cell-reader-email">{{ $row['reader_email'] }}</div>
            </td>
            <td class="cell-book">{{ $row['book_title'] }}</td>
            <td class="cell-date">
                {{ \Carbon\Carbon::parse($row['due_date'])->format('d/m/Y') }}
            </td>
            <td class="cell-days">
                <span class="cell-days-num">{{ $row['overdue_days'] }} ngày</span><br/>
                @php
                    $badgeClass = match($row['status']) {
                        'low'    => 'badge-low',
                        'medium' => 'badge-medium',
                        default  => 'badge-high',
                    };
                    $badgeLabel = match($row['status']) {
                        'low'    => 'Nhẹ',
                        'medium' => 'Vừa',
                        default  => 'Nặng',
                    };
                @endphp
                <span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span>
            </td>
            <td>
                @if($row['fine_amount'] > 0)
                    <div class="cell-fine">
                        {{ number_format($row['fine_amount'], 0, ',', '.') }}đ
                    </div>
                @else
                    <div class="cell-fine-zero">—</div>
                @endif
            </td>
        </tr>
        @endforeach
    </tbody>
</table>
@endif

</body>
</html>
