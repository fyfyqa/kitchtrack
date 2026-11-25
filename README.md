# KitchTrack — Restaurant Restock & Petty Cash Management System

KitchTrack is a mobile-first internal web application built with **Plain PHP (PDO)**, **MySQL**, **Bootstrap 5**, **Vanilla JavaScript (`fetch` API)**, and **Chart.js** to help restaurant owners and staff manage daily restocking, shopping trips, delivery verification, and petty cash.

## Features

### Owner Interface (`owner/`)
- **Analytics Dashboard:** Monthly spending summary, pending change tracker, 30-day **Price Alerts** (comparing unit prices and store names across trips), 6-month Chart.js spending bar chart, Top 5 highest-cost items, and Print / Export CSV report.
- **Petty Cash Management:** Issue Cash or Online Transfer / QR budgets to staff before trips, inspect multiple uploaded receipt photos per trip, review auto-calculated change/reimbursement, and confirm change returned.
- **Staff Management:** Create/edit staff accounts and toggle **Active / Inactive** status (soft deactivation preserves past logs and receipts).
- **Templates & Store Categories:** Manage Quick-Restock Essential Items and Store Categories.

### Staff Interface (`staff/`)
- **Live Shopping List (`staff/shopping_list.php`):** Auto-refreshes every 5 seconds via `fetch()`, urgent items pinned to the top, 1-click Quick Add from essential templates, grouped by Store Category, and single-shopper **Start Shopping** trip lock.
- **Checkout (`staff/checkout.php`):** Record store name and price paid per item, auto-calculate total spent & change, and upload multiple receipt photos per trip.
- **Receive Deliveries (`staff/receive.php`):** Dedicated verification tab with a live red badge count showing purchased items and quantities for delivery checks (buyers cannot self-confirm their own deliveries).
- **Trip History (`staff/history.php`):** Date-filtered trip history (defaults to Today) with full accountability logs (`Requested by`, `Bought by`, `Received by` + timestamps) and multi-receipt viewer.

## Setup Instructions (XAMPP)
1. Copy this folder into `C:\xampp\htdocs\kitchtrack`.
2. Start **Apache** and **MySQL** in XAMPP Control Panel.
3. Open `http://localhost/phpmyadmin`, go to **Import**, and import `database.sql` (creates `db_kitchtrack` with sample data).
4. Open `http://localhost/kitchtrack/` in your browser.

## Demo Login Accounts
All sample accounts use password: `password123`

| Role | Name | Username | Status |
| :--- | :--- | :--- | :--- |
| Owner | Hakim Rahman | `owner` | Active |
| Staff | Siti Aminah | `siti` | Active |
| Staff | Ali Hassan | `ali` | Active |
| Staff | Farah Nadia | `farah` | Inactive (demo blocked login) |
