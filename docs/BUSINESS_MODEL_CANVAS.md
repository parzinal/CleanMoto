# Business Model Canvas - CleanMoto

## Purpose
This document defines the Business Model Canvas for CleanMoto based on the current system implementation.

## Scope Used For This Canvas
- Role-based platform: admin, staff, user
- User booking flow for helmet cleaning services
- Staff walk-in booking flow
- QR-based appointment check-in and status updates
- Notifications for operational and customer updates

## Canvas Summary (9 Building Blocks)

| Building Block | CleanMoto (System-Based) |
|---|---|
| Customer Segments | Helmet owners and commuters needing cleaning, walk-in customers near the shop, repeat customers with accounts, and potential B2B fleets (delivery/courier riders). |
| Value Propositions | Fast and convenient helmet cleaning booking, QR check-in for faster service intake, clear service and add-on selection, appointment tracking with notifications, and both online and walk-in accommodation. |
| Channels | Web landing page, user dashboard pages, in-shop staff pages, QR scanner page, notification center, and email channel for account recovery. |
| Customer Relationships | Self-service booking and profile management, assisted in-store booking by staff, status transparency via notifications, and account-based retention through appointment history. |
| Revenue Streams | Per-appointment service fees, add-on upsells, walk-in transaction volume, and future options such as subscription bundles or fleet contracts. |
| Key Resources | CleanMoto web platform, appointment and user database, service catalog and pricing setup, staff operations team, and cleaning equipment and supplies. |
| Key Activities | Appointment scheduling, walk-in intake, service execution, QR check-in and status updates, service catalog management, and customer communication through notifications. |
| Key Partnerships | Helmet-care supply vendors, local rider communities or clubs, hosting/infrastructure providers, and potential payment or fleet partners. |
| Cost Structure | Staff labor, cleaning consumables, equipment maintenance, platform hosting and maintenance, security hardening, and local marketing/promotions. |

## System Feature to Business Model Mapping

| System Feature | Business Impact |
|---|---|
| User appointment booking | Drives core revenue and customer convenience |
| Add-ons in booking flow | Increases average transaction value |
| Staff walk-in calendar | Captures offline demand and improves utilization |
| QR scanner check-in | Reduces intake friction and operational delays |
| Notifications | Improves communication quality and customer trust |
| Admin service management | Enables pricing, packaging, and offer iteration |

## Recommended KPI Set
- Bookings per day (online vs walk-in)
- Appointment completion rate
- Average order value (service + add-ons)
- Repeat booking rate
- No-show and cancellation rate
- Time from check-in to completion
- Notification engagement rate

## Practical Next Moves
1. Add payment confirmation workflow to strengthen the revenue capture loop.
2. Add simple loyalty logic (for example, every N bookings gets a discount) to increase repeat rate.
3. Build partner pricing packages for fleet or rider-group accounts.
4. Add analytics dashboard cards directly tied to the KPI set above.
