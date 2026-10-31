<<<<<<< HEAD
# Squash Club Booking & Management System

> **A modern, production-ready WordPress plugin for managing squash club bookings, private coaching, summer camps, waitlists, payments, and notifications.**

---

## 🏸 Table of Contents

1. [Overview](#overview)
2. [Key Services & Features](#key-services--features)
3. [Architecture & Technology Stack](#architecture--technology-stack)
4. [Custom Admin Dashboard](#custom-admin-dashboard)
5. [Frontend Shortcodes](#frontend-shortcodes)
6. [Database Schema (12 Custom Tables)](#database-schema-12-custom-tables)
7. [WooCommerce Payment Integration](#woocommerce-payment-integration)
8. [Email Notification System](#email-notification-system)
9. [Automated Cron Tasks](#automated-cron-tasks)
10. [Role-Based Access Control (Capabilities)](#role-based-access-control-capabilities)
11. [Installation & Setup](#installation--setup)

---

## 1. Overview

**Squash Club Booking** is a custom WordPress plugin developed specifically for squash clubs and racquets sports centers. It replaces fragmented forms and generic appointment plugins with an integrated, high-performance booking and sports club management platform.

### Core Objectives:
- **Zero Placeholders**: 100% database-driven backend with atomic updates and transactional consistency.
- **Modern SaaS Aesthetics**: Custom-built admin dashboard (SPA-feel) with Inter typography, clean metrics cards, dynamic status badges, and interactive modals.
- **Seamless WooCommerce Checkout**: Automatic order creation, checkout redirection, and automatic booking status synchronization on payment events.
- **Mobile-Responsive Frontend**: Fast, card-based booking widgets with real-time slot selection, coach filtering, and booking lookup.

---

## 2. Key Services & Features

### 1. Sunday Squash Clinics
- Weekly group training sessions held every Sunday:
  - Junior Clinics: 9:00 AM – 10:00 AM, 10:00 AM – 11:00 AM, 11:00 AM – 12:00 PM
  - Adult Clinic: 12:00 PM – 1:00 PM
- Configurable per-slot capacity (default 15 players per session) with atomic counters (`booked_count`).
- Automated multi-week slot generation with conflict and duplicate prevention.
- Automated Waitlist: When a clinic is full, customers can join a priority-ordered waitlist and be promoted automatically or manually.

### 2. Saturday Private & Semi-Private Lessons
- Bookings for Saturday coaching with certified squash professionals.
- Lesson options:
  - **Private Lesson (1-on-1)**
  - **Semi-Private Lesson (2 players)** with secondary participant fields.
- Overlap & Conflict Prevention: Prevents double-booking coaches, respects working hours, lunch breaks, and existing reservations.
- Interactive Slot Picker: Real-time availability endpoint (`/coaches/{id}/available-slots?date=YYYY-MM-DD`).

### 3. Summer Camp Registration
- Multi-day intensive youth squash academies (beginner through elite competitive).
- Age eligibility enforcement (`min_age` and `max_age` validation).
- Registration deadline enforcement.
- Parent/guardian information, emergency contacts, medical/equipment notes, and liability waivers.

### 4. Booking Status & History
- Real-time booking tracking widget by unique reference number (e.g. `SCB-CLN-20261025-WT2M`, `SCB-LES-20261024-376Y`, `SCB-CMP-20261019-GI0T`).
- Customer portal shortcode (`[scb_my_bookings]`) for logged-in members to view upcoming and historical bookings.

---

## 3. Architecture & Technology Stack

```
squash-club-booking/
├── assets/
│   ├── admin/
│   │   ├── css/admin.css            # Complete design system & admin styling
│   │   └── js/admin.js              # Single-page admin controller (12 views)
│   └── frontend/
│       ├── css/frontend.css         # Modern, mobile-first frontend styles
│       └── js/frontend.js           # Client-side widgets for all shortcodes
├── includes/
│   ├── Admin/
│   │   ├── Controllers/             # REST controllers for all admin entities
│   │   │   ├── BookingController.php
│   │   │   ├── CampController.php
│   │   │   ├── ClinicController.php
│   │   │   ├── CoachController.php
│   │   │   ├── CustomerController.php
│   │   │   ├── NotificationController.php
│   │   │   ├── PaymentController.php
│   │   │   ├── ReportController.php
│   │   │   ├── SettingsController.php
│   │   │   └── WaitlistController.php
│   │   └── Dashboard.php            # Admin menu, screen routing, view wrapper
│   ├── Core/
│   │   ├── Assets.php               # Conditional script and style enqueuing
│   │   ├── AuditLog.php             # System activity and audit logger
│   │   ├── Capabilities.php         # Granular permissions registration
│   │   ├── Cron.php                 # WP-Cron schedules & recurring jobs
│   │   ├── Frontend.php             # Shortcode registration and view rendering
│   │   ├── Plugin.php               # Main plugin bootstrapper and lifecycle
│   │   ├── RestApi.php              # REST API namespace and route registration
│   │   └── Settings.php             # Dynamic settings store with database caching
│   ├── Database/
│   │   ├── Migrations.php           # Schema delta executor
│   │   ├── Schema.php               # 12 custom SQL table definitions
│   │   └── Seeder.php               # Initial coaches, clinic slots, and camps
│   └── Modules/
│       ├── Camps/                   # Summer camp registration logic & handlers
│       ├── Clinics/                 # Clinic slot generator and booking handler
│       ├── Lessons/                 # Lesson booking and conflict validation
│       ├── Notifications/           # HTML email templates and delivery logger
│       ├── Payments/                # WooCommerce order hooks & sync manager
│       └── Waitlist/                # Waitlist ordering, promotion, and expiry
├── templates/
│   ├── admin/
│   │   └── dashboard.php            # Root container for admin app
│   └── frontend/
│       ├── booking-status.php       # [scb_booking_status] container
│       ├── camp-registration.php    # [scb_summer_camp] container
│       ├── clinic-booking.php       # [scb_sunday_clinic] container
│       ├── lesson-booking.php       # [scb_private_lesson] container
│       └── my-bookings.php          # [scb_my_bookings] container
├── squash-club-booking.php          # Plugin bootstrap file
└── README.md                        # Documentation
```

---

## 4. Custom Admin Dashboard

Accessed via **WP Admin > Squash Club** (`admin.php?page=scb-dashboard`).

### 12 Integrated Admin Views:
1. **Overview**: Key metrics (revenue, total bookings, active camps, waitlisted players), quick actions, and recent activity feed.
2. **All Bookings**: Filterable table (search, service type, booking status, payment status, date range) with inline view, approve, reject, reschedule, and cancel actions.
3. **Sunday Clinics**: Slot management, real-time capacity progress bars, attendee modals, add/edit slots, and 1-click **Generate 4 Weeks** tool.
4. **Private Lessons**: Weekly Saturday schedule view, coach assignments, participant cards, and booking conflict management.
5. **Coaches Directory**: Staff directory with photos, bios, squash specialties, hourly rates, Saturday availability schedules, and active/inactive toggles.
6. **Master Schedule**: Chronological timeline of all weekend court sessions, clinic times, and private lesson blocks.
7. **Waitlist Management**: Real-time queue view with position counters, 1-click **Promote to Booking** (automatically adjusts capacity and alerts player), and removal.
8. **Summer Camps**: Camp editor (dates, age brackets, pricing, capacity, publication status) and participant rosters.
9. **Camp Registrations**: Detailed list of registered youth athletes, parent contacts, emergency info, and payment statuses.
10. **Payments**: Financial transaction log linked directly to WooCommerce orders, refunds, and payment gateways.
11. **Customers**: Aggregated member directory with total visits, lifetime spend, contact details, and booking history.
12. **Notifications & Logs**: Comprehensive outgoing email audit trail with delivery statuses, recipient addresses, and system audit logs.
13. **Reports & Exports**: High-level revenue and attendance metrics + instant **CSV Export** for Bookings, Camp Registrations, and Customer Lists.
14. **Settings**: Configurable club details, operating hours, lesson pricing, automated notifications, currency formatting, and WooCommerce options.

---

## 5. Frontend Shortcodes

Place these shortcodes on any WordPress page:

| Shortcode | Description |
|---|---|
| `[scb_sunday_clinic]` | Interactive Sunday clinic slot selector, capacity badges, attendee registration modal, and waitlist opt-in. |
| `[scb_private_lesson]` | Coach selection cards, date picker, real-time availability slots, and private/semi-private booking form. |
| `[scb_summer_camp]` | Camp programs directory with age restrictions, registration progress, and parent registration modal. |
| `[scb_my_bookings]` | Logged-in member portal showing past and upcoming bookings, payment statuses, and reference numbers. |
| `[scb_booking_status ref="REF"]` | Instant booking reference lookup tool with real-time status display and payment links. |

---

## 6. Database Schema (12 Custom Tables)

The plugin creates 12 dedicated database tables with indexes, foreign keys, and unique constraints:

1. `wp_scb_settings`: Plugin configuration store.
2. `wp_scb_coaches`: Coach profiles, specialties, bios, images, and hourly rates.
3. `wp_scb_coach_availability`: Weekly working hours, lunch breaks, and day-of-week rules.
4. `wp_scb_clinic_slots`: Sunday clinic time slots, categories, capacity, and attendee counts.
5. `wp_scb_bookings`: Master booking records (clinics, private lessons, semi-private lessons).
6. `wp_scb_booking_participants`: Individual player details, skill levels, and secondary players.
7. `wp_scb_waitlist`: Queue records with position rankings and promotion timestamps.
8. `wp_scb_camps`: Summer camp programs, age limits, capacities, and deadlines.
9. `wp_scb_camp_registrations`: Camp attendees, parent/guardian info, and medical notes.
10. `wp_scb_payments`: Financial transactions linked to WooCommerce orders and refund records.
11. `wp_scb_notifications`: Outgoing email delivery logs, subjects, recipients, and timestamps.
12. `wp_scb_audit_log`: Administrative audit trail logging actions, old values, and new values.

---

## 7. WooCommerce Payment Integration

- **Automatic Order Generation**: When a customer books a clinic, lesson, or camp, a WooCommerce order is programmatically generated with correct item descriptions, prices, taxes, and customer billing info.
- **Pay Upon Booking**: Customers receive an instant WooCommerce checkout link (`checkout/order-pay/{order_id}/?pay_for_order=true&key=...`) to complete payment via credit card, PayPal, Stripe, etc.
- **Bi-directional Webhooks / Status Hooks**:
  - `woocommerce_order_status_completed` / `processing`: Marks booking or camp registration as `paid` and `approved`.
  - `woocommerce_order_status_cancelled` / `failed`: Releases reserved capacity back to the slot or camp.
  - `woocommerce_order_status_refunded`: Marks payment status as `refunded` and updates audit logs.
- **Abandoned Checkout Protection**: Background cron job releases capacity for unpaid checkouts older than the configured threshold (default 60 minutes).

---

## 8. Email Notification System

All outgoing emails use a custom, branded, responsive HTML template with inline CSS:

- **Customer Notifications**:
  - Booking Confirmation (with booking ref, date, time, coach/session, and payment status).
  - Waitlist Entry Confirmation (with queue position).
  - Waitlist Promotion Alert (prompting player that a spot opened up).
  - Camp Registration Confirmation.
  - Cancellation & Reschedule notices.
- **Admin Alerts**:
  - Instant email notification sent to club management upon new bookings or camp registrations.
- **Notification Logging**: Every email sent or failed is logged in `wp_scb_notifications` for troubleshooting and delivery confirmation.

---

## 9. Automated Cron Tasks

Registered with `WP-Cron`:

1. `scb_weekly_clinic_generation`: Runs weekly to generate the next 4 weeks of Sunday clinic sessions according to the club schedule.
2. `scb_cleanup_abandoned_checkouts`: Runs hourly to release slots from uncompleted checkout sessions.
3. `scb_check_waitlist_expiry`: Runs hourly to check promoted waitlist entries that exceeded the payment deadline and offer the spot to the next player in line.

---

## 10. Role-Based Access Control (Capabilities)

Administrators have full access by default. Custom roles (e.g. `Coach`, `Front Desk`, `Club Manager`) can be assigned granular capabilities:

- `scb_manage_bookings`
- `scb_manage_clinics`
- `scb_manage_lessons`
- `scb_manage_coaches`
- `scb_manage_camps`
- `scb_manage_waitlist`
- `scb_manage_payments`
- `scb_view_reports`
- `scb_manage_settings`

---

## 11. Installation & Setup

1. Place the plugin directory inside `wp-content/plugins/squash-club-booking`.
2. Activate the plugin via **WP Admin > Plugins**.
3. Upon activation, all 12 database tables will be created and default seed data (9 certified coaches, 4 weeks of Sunday clinic slots, and 3 summer camps) will be populated.
4. Navigate to **Squash Club > Settings** to verify your club name, currency, and notification emails.
5. Create standard WordPress pages and paste any of the shortcodes:
   - Sunday Clinics: `[scb_sunday_clinic]`
   - Private Lessons: `[scb_private_lesson]`
   - Summer Camps: `[scb_summer_camp]`
   - Booking Status: `[scb_booking_status]`
   - My Bookings: `[scb_my_bookings]`
=======
# squash-club-booking
>>>>>>> 6f0d6d641802372c4832c492d7875f94c622d359
