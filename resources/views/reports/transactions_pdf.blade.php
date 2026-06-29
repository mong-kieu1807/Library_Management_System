<!DOCTYPE html>
<html lang="vi">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Báo cáo giao dịch mượn/trả</title>
    <style>
        /*
         * QUAN TRỌNG — ràng buộc DomPDF:
         *  1. Font nhiều từ PHẢI có dấu nháy đơn: 'DejaVu Sans'
         *  2. KHÔNG dùng display:flex hay display:grid
         *  3. KHÔNG dùng calc() hay CSS variable
         *  4. Dùng <table> cho mọi layout nhiều cột
         *  5. @page chỉ hỗ trợ margin — KHÔNG dùng size
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
            border-bottom: 2px solid #1d4ed8;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        .library-name {
            font-size: 15px;
            font-weight: bold;
            color: #1d4ed8;
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

        /* ── Meta info (2 cột dùng table) ───────────────────────── */
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
        .meta-value { color: #111; font-weight: bold; }

        /* ── Section title ──────────────────────────────────────── */
        .section-title {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #1d4ed8;
            border-left: 3px solid #1d4ed8;
            padding-left: 6px;
            margin-bottom: 6px;
        }

        /* ── Summary 2×2 table ───────────────────────────────────── */
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        .summary-table td {
            width: 25%;
            padding: 8px 10px;
            vertical-align: top;
            border: 1px solid #e5e7eb;
        }
        .sum-label {
            font-size: 8px;
            color: #6b7280;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            display: block;
            margin-bottom: 4px;
        }
        .sum-value {
            font-size: 18px;
            font-weight: bold;
            display: block;
        }
        .sum-note {
            font-size: 7px;
            color: #9ca3af;
            margin-top: 3px;
            display: block;
        }
        .sum-blue    { background: #eff6ff; border-left: 3px solid #1d4ed8; }
        .sum-green   { background: #f0fdf4; border-left: 3px solid #16a34a; }
        .sum-purple  { background: #faf5ff; border-left: 3px solid #7c3aed; }
        .sum-red     { background: #fff1f2; border-left: 3px solid #dc2626; }
        .val-blue    { color: #1d4ed8; }
        .val-green   { color: #16a34a; }
        .val-purple  { color: #7c3aed; }
        .val-red     { color: #dc2626; }

        /* ── Data table ─────────────────────────────────────────── */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
        }
        .data-table thead tr { background: #1d4ed8; }
        .data-table th {
            color: #fff;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 6px 6px;
            text-align: left;
            vertical-align: middle;
            border: 1px solid #1e40af;
        }
        .data-table td {
            padding: 5px 6px;
            vertical-align: middle;
            border: 1px solid #e5e7eb;
        }
        .data-table tr:nth-child(even) td { background: #f0f7ff; }
        .data-table tr:nth-child(odd)  td { background: #ffffff; }

        /* Footer tổng cộng */
        .data-table tfoot td {
            background: #dbeafe;
            font-weight: bold;
            border-top: 2px solid #1d4ed8;
            border-bottom: 1px solid #1d4ed8;
            border-left: 1px solid #1d4ed8;
            border-right: 1px solid #1d4ed8;
        }

        /* ── Cell alignment ─────────────────────────────────────── */
        .cell-stt     { text-align: center; color: #6b7280; width: 28px; }
        .cell-period  { font-weight: 500; }
        .cell-number  { text-align: right; font-weight: bold; }
        .cell-total-label { color: #1d4ed8; font-weight: bold; }

        /* ── Footer số trang ────────────────────────────────────── */
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
        .page-num::after   { content: counter(page); }
        .page-total::before { content: " / "; }
        .page-total::after  { content: counter(pages); }
        .page-num { counter-increment: page; }

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
<div class="report-title">Báo cáo giao dịch mượn / trả</div>
<div class="report-subtitle">{{ $groupByLabel }}</div>

{{-- ── Meta info ────────────────────────────────────────────────────── --}}
<table class="meta-table">
    <tr>
        <td>
            <span class="meta-label">Thời gian xuất</span><br/>
            <span class="meta-value">{{ $generatedAt }}</span>
        </td>
        <td>
            <span class="meta-label">Khoảng thời gian</span><br/>
            <span class="meta-value">{{ $fromLabel }} – {{ $toLabel }}</span>
        </td>
    </tr>
</table>

{{-- ── Summary 4 chỉ số — dùng table 2×2 (DomPDF không hỗ trợ flex/grid) ── --}}
<div class="section-title">Tổng kết trong kỳ</div>
<table class="summary-table">
    <tr>
        <td class="sum-blue">
            <span class="sum-label">Tổng lượt mượn</span>
            <span class="sum-value val-blue">{{ number_format($summary['total_borrows']) }}</span>
            <span class="sum-note">Trong khoảng thời gian đã chọn</span>
        </td>
        <td class="sum-green">
            <span class="sum-label">Tổng lượt trả</span>
            <span class="sum-value val-green">{{ number_format($summary['total_returns']) }}</span>
            <span class="sum-note">Trong khoảng thời gian đã chọn</span>
        </td>
        <td class="sum-purple">
            <span class="sum-label">Đang mượn</span>
            <span class="sum-value val-purple">{{ number_format($summary['active_borrows']) }}</span>
            <span class="sum-note">Thực trạng tại thời điểm xuất</span>
        </td>
        <td class="sum-red">
            <span class="sum-label">Quá hạn</span>
            <span class="sum-value val-red">{{ number_format($summary['overdue']) }}</span>
            <span class="sum-note">Thực trạng tại thời điểm xuất</span>
        </td>
    </tr>
</table>

{{-- ── Chi tiết theo kỳ ─────────────────────────────────────────────── --}}
<div class="section-title">Chi tiết theo kỳ</div>

@php
    $totalBorrows = array_sum(array_column($chart, 'borrows'));
    $totalReturns = array_sum(array_column($chart, 'returns'));
@endphp

@if(empty($chart))
    <p style="text-align:center; color:#6b7280; margin-top:20px; font-size:11px;">
        Không có dữ liệu trong khoảng thời gian đã chọn.
    </p>
@else
<table class="data-table">
    <thead>
        <tr>
            <th style="width:28px">#</th>
            <th>Kỳ</th>
            <th style="width:100px; text-align:right">Lượt mượn</th>
            <th style="width:100px; text-align:right">Lượt trả</th>
        </tr>
    </thead>
    <tbody>
        @foreach($chart as $i => $row)
        <tr>
            <td class="cell-stt">{{ $i + 1 }}</td>
            <td class="cell-period">{{ $row['label'] }}</td>
            <td class="cell-number">{{ number_format($row['borrows']) }}</td>
            <td class="cell-number">{{ number_format($row['returns']) }}</td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td colspan="2" class="cell-total-label" style="text-align:right; padding-right:10px">
                Tổng cộng
            </td>
            <td class="cell-number">{{ number_format($totalBorrows) }}</td>
            <td class="cell-number">{{ number_format($totalReturns) }}</td>
        </tr>
    </tfoot>
</table>
@endif

</body>
</html>
