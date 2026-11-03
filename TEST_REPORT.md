# Test report — v0.15.0 · build 20261103.4

**Result: 113 of 113 tests passed** on your real-data replica (MySQL 8.0.46) and on synthetic data (MariaDB 10.11), and on a copy **installed by `cm-update v0.15.0` from a git release branch** over v0.14.0. Zero PHP warnings, zero security-policy violations and zero JavaScript errors in every run.
Run in a private workspace, **not** on your server. Deployed / user-confirmed status: pending.

## 1. Runs
| Database | Data | Code under test | Result |
|---|---|---|---|
| MariaDB 10.11 | Synthetic fixture | dev tree | **113/113** |
| MySQL 8.0.46 | Real-data replica | dev tree | **113/113** |
| MySQL 8.0.46 | Real-data replica | **installed by `cm-update v0.15.0`** (fetch release branch → build → dry run → `y` → install.sh: backup, swap, verify) over v0.14.0 | **113/113** |
| MySQL 8.0.46 | Real-data replica | **back to v0.14.0 with `cm-update v0.14.0`** — v0.14.0's own suite | **111/111** |

Also seen in the rehearsal: when the installer's check of the site fails, it **rolls back by itself** and the old version stays live (simulated server could not be reached on its real address).

## 2. New / changed tests
| ID | Proven |
|---|---|
| W01 | Desktop sidebar is exactly Today, Tasks, Work Log, Clients, Enquiries, More…; each loads. More… lists Workload, Task templates, Staff & hours, Pending Invoicing, Services, Month-end claims, Billing settings, Account Items, Release check and Enquiries; each loads with More… highlighted |
| W02 | A plain task shows Log work and sign-off with **More options closed** (pricing and materials hidden); clicking it shows pricing and Make a template; a `#materials` link opens it; a task with price lines opens it by itself and says "price line" in its heading |
| W03 | "Cloake Workdesk" in page titles, header, More page and sign-in page |
| Y01, Y02 | Now open **More details** before filling category/type/domains/queue/job no (as a person would); values save as before |
| K09 | Replaced by W01 (the sidebar no longer lists the advanced screens) |

Layout tests at 320/375/390/430/1280 px passed for every screen (no sideways scrolling with the longer "Cloake Workdesk" header). P01 (imported records and locked fields unchanged) passed in every run.

## 3. Not covered here
- Your real server and GitHub's connection to it (set up and checked by you on 3 Nov: `cm-update` listed v0.14.0).
- Phones other than Chromium's emulation.
