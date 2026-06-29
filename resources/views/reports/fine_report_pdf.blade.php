<!DOCTYPE html>
<html lang="vi">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Báo cáo doanh thu tiền phạt</title>
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
            border-bottom: 2px solid #d97706;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        .library-name {
            font-size: 15px;
            font-weight: bold;
            color: #d97706;
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

        /* ── Summary 2 ô (1×2 table) ────────────────────────────── */
        .summary-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
        }
        .summary-table td {
            width: 50%;
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
        .sum-orange { background: #fffbeb; border-left: 3px solid #d97706; }
        .sum-green  { background: #f0fdf4; border-left: 3px solid #16a34a; }
        .val-orange { color: #d97706; }
        .val-green  { color: #16a34a; }

        /* ── Section title ──────────────────────────────────────── */
        .section-title {
            font-size: 10px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #d97706;
            border-left: 3px solid #d97706;
            padding-left: 6px;
            margin-bottom: 6px;
            margin-top: 14px;
        }
        .section-note {
            font-size: 8px;
            color: #9ca3af;
            margin-bottom: 6px;
        }

        /* ── Data table ─────────────────────────────────────────── */
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 9px;
            margin-bottom: 4px;
        }
        .data-table thead tr { background: #d97706; }
        .data-table th {
            color: #fff;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 6px 6px;
            text-align: left;
            vertical-align: middle;
            border: 1px solid #b45309;
        }
        .data-table td {
            padding: 5px 6px;
            vertical-align: middle;
            border: 1px solid #e5e7eb;
        }
        .data-table tr:nth-child(even) td { background: #fffbeb; }
        .data-table tr:nth-child(odd)  td { background: #ffffff; }

        /* Footer tổng cộng */
        .data-table tfoot td {
            background: #fef3c7;
            font-weight: bold;
            border-top: 2px solid #d97706;
            border-bottom: 1px solid #d97706;
            border-left: 1px solid #d97706;
            border-right: 1px solid #d97706;
        }

        /* ── Cell alignment ─────────────────────────────────────── */
        .cell-stt          { text-align: center; color: #6b7280; width: 28px; }
        .cell-period       { font-weight: 500; }
        .cell-number       { text-align: right; font-weight: bold; }
        .cell-money        { text-align: right; font-weight: bold; color: #d97706; }
        .cell-total-label  { color: #d97706; font-weight: bold; }

        /* Reasons table — nguyên nhân */
        .reasons-table { margin-bottom: 4px; }
        .reasons-table thead tr { background: #92400e; }
        .reasons-table th { border: 1px solid #78350f; }
        .reasons-table tr:nth-child(even) td { background: #fff7ed; }
        .reasons-table tfoot td {
            background: #fef3c7;
            font-weight: bold;
            border-top: 2px solid #d97706;
            border-bottom: 1px solid #d97706;
            border-left: 1px solid #d97706;
            border-right: 1px solid #d97706;
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            color: #6b7280;
            margin: 16px 0 6px;
            font-size: 10px;
        }

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
        .page-num::before  { content: "Trang "; }
        .page-num          { counter-increment: page; }
        .page-num::after   { content: counter(page); }
        .page-total::before { content: " / "; }
        .page-total::after  { content: counter(pages); }

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
<div class="report-title">Báo cáo doanh thu tiền phạt</div>
<div class="report-subtitle">{{ $fromLabel }} – {{ $toLabel }}</div>

{{-- ── Meta info ────────────────────────────────────────────────────── --}}
<table class="meta-table">
    <tr>
        <td>
            <span class="meta-label">Thời gian xuất</span><br/>
            <span class="meta-value">{{ $generatedAt }}</span>
        </td>
        <td>
            <span class="meta-label">Khoảng thời gian (doanh thu)</span><br/>
            <span class="meta-value">{{ $fromLabel }} – {{ $toLabel }}</span>
        </td>
    </tr>
</table>

{{-- ── Summary 2 ô ─────────────────────────────────────────────────── --}}
<table class="summary-table">
    <tr>
        <td class="sum-orange">
            <span class="sum-label">Tổng doanh thu</span>
            <span class="sum-value val-orange">{{ number_format($totalRevenue, 0, ',', '.') }}đ</span>
            <span class="sum-note">Tổng tiền thực thu trong kỳ</span>
        </td>
        <td class="sum-green">
            <span class="sum-label">Tổng phiếu đã thanh toán</span>
            <span class="sum-value val-green">{{ number_format($totalFineCount) }}</span>
            <span class="sum-note">Số phiếu phạt đã thanh toán trong kỳ</span>
        </td>
    </tr>
</table>

{{-- ── Section 1: Doanh thu theo tháng ────────────────────────────── --}}
<div class="section-title">Doanh thu theo tháng</div>

@php
    $revenueList = $revenue;
@endphp

@if(empty($revenueList) || $totalRevenue == 0 && $totalFineCount == 0)
    <p class="empty-state">Không có dữ liệu thanh toán trong khoảng thời gian đã chọn.</p>
@else
<table class="data-table">
    <thead>
        <tr>
            <th style="width:28px">#</th>
            <th>Tháng</th>
            <th style="width:130px; text-align:right">Doanh thu</th>
            <th style="width:110px; text-align:right">Số phiếu đã thanh toán</th>
        </tr>
    </thead>
    <tbody>
        @foreach($revenueList as $i => $row)
        <tr>
            <td class="cell-stt">{{ $i + 1 }}</td>
            <td class="cell-period">{{ $row['label'] }}</td>
            <td class="cell-money">
                @if($row['revenue'] > 0)
                    {{ number_format($row['revenue'], 0, ',', '.') }}đ
                @else
                    <span style="color:#9ca3af; font-weight:normal">—</span>
                @endif
            </td>
            <td class="cell-number">
                @if($row['fine_count'] > 0)
                    {{ number_format($row['fine_count']) }}
                @else
                    <span style="color:#9ca3af; font-weight:normal">—</span>
                @endif
            </td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td colspan="2" class="cell-total-label" style="text-align:right; padding-right:10px">
                Tổng cộng
            </td>
            <td class="cell-money">{{ number_format($totalRevenue, 0, ',', '.') }}đ</td>
            <td class="cell-number">{{ number_format($totalFineCount) }}</td>
        </tr>
    </tfoot>
</table>
@endif

{{-- ── Section 2: Phân loại nguyên nhân (all-time) ────────────────── --}}
<div class="section-title">Phân loại nguyên nhân phát sinh tiền phạt</div>
<div class="section-note">* Thống kê toàn bộ lịch sử — không phụ thuộc khoảng thời gian phía trên</div>

@php
    $totalReasonCount  = array_sum(array_column($reasons, 'fine_count'));
    $totalReasonAmount = array_sum(array_column($reasons, 'total_amount'));
@endphp

<table class="data-table reasons-table">
    <thead>
        <tr>
            <th style="width:28px">#</th>
            <th>Nguyên nhân</th>
            <th style="width:90px; text-align:right">Số lượt</th>
            <th style="width:130px; text-align:right">Tổng tiền phạt</th>
        </tr>
    </thead>
    <tbody>
        @foreach($reasons as $i => $row)
        <tr>
            <td class="cell-stt">{{ $i + 1 }}</td>
            <td class="cell-period">{{ $row['category'] }}</td>
            <td class="cell-number">
                @if($row['fine_count'] > 0)
                    {{ number_format($row['fine_count']) }}
                @else
                    <span style="color:#9ca3af; font-weight:normal">0</span>
                @endif
            </td>
            <td class="cell-money">
                @if($row['total_amount'] > 0)
                    {{ number_format($row['total_amount'], 0, ',', '.') }}đ
                @else
                    <span style="color:#9ca3af; font-weight:normal">—</span>
                @endif
            </td>
        </tr>
        @endforeach
    </tbody>
    <tfoot>
        <tr>
            <td colspan="2" class="cell-total-label" style="text-align:right; padding-right:10px">
                Tổng cộng
            </td>
            <td class="cell-number">{{ number_format($totalReasonCount) }}</td>
            <td class="cell-money">{{ number_format($totalReasonAmount, 0, ',', '.') }}đ</td>
        </tr>
    </tfoot>
</table>

</body>
</html>
