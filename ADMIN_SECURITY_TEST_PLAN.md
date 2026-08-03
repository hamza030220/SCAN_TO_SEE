# Scan to See — Admin Security Test Plan

Use this document as both:

1. A step-by-step manual test plan.
2. A feedback report to send back if something fails.

Do not test permanent deletion with an important account or a real paid Stripe
subscription. Create a disposable owner account specifically for these tests.

## Test session information

- Tester: hamza slimani 
- Test date: 03/08/2026
- Browser and version: chrome
- Screen size/device: 1920/1080 PC / phone
- Symfony URL: http://localhost:8000
- Public ngrok URL, if used: https://easiest-crescent-reveler.ngrok-free.dev 
- Git commit expected: `0d81eea`
- Git commit tested:
- Notes about the environment:

Overall result:

- [ ] All tests passed
- [ ] Passed with minor problems
- [ ] One or more important tests failed
- [ ] Testing blocked by the environment

## 1. Preparation

### 1.1 Start the required infrastructure

Start MySQL in XAMPP. Apache is optional when the application is served by
Symfony CLI.

Open PowerShell in the Symfony project:

```powershell
cd C:\Users\zussl\Desktop\scantosee\scantosee_APP\my_project_directory
```

Confirm that the correct branch and commit are present:

```powershell
git status -sb

PS C:\Users\zussl\Desktop\scantosee\scantosee_APP\my_project_directory> git status -sb
## master...origin/master
?? ADMIN_SECURITY_TEST_PLAN.md
git log -1 --oneline

PS C:\Users\zussl\Desktop\scantosee\scantosee_APP\my_project_directory> git log -1 --oneline
0d81eea (HEAD -> master, origin/master, origin/HEAD) Merge pull request #6 from hamza030220/agent/admin-workflow-tests
```

Expected:

- The branch is `master`.
- Local `master` is synchronized with `origin/master`.
- The latest commit is `0d81eea` or a newer commit containing it.
- There are no unexpected local modifications.

Result:

- [X] Pass
- [ ] Fail
- [ ] Blocked

Actual result / problem description:

```text

```

### 1.2 Apply the database migration

```powershell
php bin/console doctrine:migrations:migrate --no-interaction

 [OK] Already at the latest version ("DoctrineMigrations\Version20260731090000")
```

Then check its status:

```powershell
php bin/console doctrine:migrations:status

+----------------------+----------------------+--------------------------------------------------------------------------------+
| Configuration                                                                                                                |
+----------------------+----------------------+--------------------------------------------------------------------------------+
| Storage              | Type                 | Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration         |
|                      | Table Name           | doctrine_migration_versions                                                    |
|                      | Column Name          | version                                                                        |
|------------------------------------------------------------------------------------------------------------------------------|
| Database             | Driver               | Symfony\Bridge\Doctrine\Middleware\Debug\Driver                                |
|                      | Name                 | s2s                                                                            |
|------------------------------------------------------------------------------------------------------------------------------|
| Versions             | Previous             | DoctrineMigrations\Version20260729103000                                       |
|                      | Current              | DoctrineMigrations\Version20260731090000                                       |
|                      | Next                 | Already at latest version                                                      |
|                      | Latest               | DoctrineMigrations\Version20260731090000                                       |
|------------------------------------------------------------------------------------------------------------------------------|
| Migrations           | Executed             | 14                                                                             |
|                      | Executed Unavailable | 0                                                                              |
|                      | Available            | 14                                                                             |
|                      | New                  | 0                                                                              |
|------------------------------------------------------------------------------------------------------------------------------|
| Migration Namespaces | DoctrineMigrations   | C:\Users\zussl\Desktop\scantosee\scantosee_APP\my_project_directory/migrations |
+----------------------+----------------------+--------------------------------------------------------------------------------+
```

Expected:

- The migration command finishes successfully.
- `Version20260731090000` is marked as executed.
- The database contains the `admin_audit_log` table.
- Running the migration command a second time reports that there is nothing to
  migrate.

Result:

- [X] Pass
- [ ] Fail
- [ ] Blocked

Actual result / complete error:

```text

```

### 1.3 Run automated validation

```powershell
php bin/phpunit

PHPUnit 11.5.55 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.2.12
Configuration: C:\Users\zussl\Desktop\scantosee\scantosee_APP\my_project_directory\phpunit.dist.xml

...............................................................  63 / 165 ( 38%)
............................................................... 126 / 165 ( 76%)
.......................................                         165 / 165 (100%)

Time: 00:07.803, Memory: 20.00 MB

OK (165 tests, 466 assertions)

php bin/console lint:twig templates/admin

 [OK] All 6 Twig files contain valid syntax.

php bin/console lint:container

 [OK] The container was linted successfully: all services are injected with values that are compatible with their type
      declarations.

php bin/console doctrine:schema:validate


Mapping
-------


 [OK] The mapping files are correct.


Database
--------


 [ERROR] The database schema is not in sync with the current mapping file.
```

Expected:

- PHPUnit: `OK (165 tests, 466 assertions)`.
- All six admin Twig templates are valid.
- The Symfony container is valid.
- Doctrine mapping and database schema are valid.

Result:

- [ ] Pass
- [ ] Fail
- [ ] Blocked

Actual result / failed test names:

```text

Database
--------


 [ERROR] The database schema is not in sync with the current mapping file.

```

### 1.4 Prepare test accounts

Prepare:

- One administrator account with working password and 2FA.
- One disposable active owner account.
- A second browser or private window for the disposable owner.

Record only non-sensitive identifiers:

- Administrator email: zusslimani001122@gmail.com
- Disposable owner email:
- Disposable owner ID, if visible:
- Disposable owner initially active:
  - [X] Yes
  - [ ] No
- Disposable owner has a real paid subscription:
  - [X] No — recommended
  - [ ] Yes — do not perform permanent deletion

Never write passwords, backup codes, Stripe secrets, API secrets, or 2FA secrets
in this document.

## 2. Admin access and navigation

### Test ADM-01 — Administrator can open the audit log

Steps:

1. Sign in as the administrator.
2. Open `/admin`.
3. Select `Audit log` in the sidebar.

Expected:

- `/admin/audit` opens successfully.
- The page shows administrator activity or a professional empty state.
- The table is horizontally scrollable on a narrow screen.
- The current admin layout remains usable on desktop and mobile.

Result:

- [X] Pass
- [ ] Fail
- [ ] Blocked

Actual result:

```text

```

Screenshot filename:

```text

```

### Test ADM-02 — Owner cannot access the audit log

Steps:

1. Open a private window.
2. Sign in as the disposable owner.
3. Attempt to open `/admin/audit`.

Expected:

- Access is refused with a `403` page or the application's access-denied
  handling.
- No audit information is displayed.
- The owner is not accidentally promoted or redirected into the admin area.

Result:

- [X] Pass
- [ ] Fail
- [ ] Blocked

Actual result:

```text

```

## 3. Account deactivation and activation

### Test ADM-03 — Deactivation confirmation page

Steps:

1. Sign in as administrator.
2. Open `/admin/owners`.
3. Select `Deactivate` for the disposable owner.

Expected:

- A dedicated confirmation page opens.
- The correct target email is displayed.
- The page explains that the owner will be signed out.
- A reason is required.
- The administrator must type `CONFIRM`.
- `Cancel` returns to the owner without changing the account.

Result:

- [X] Pass
- [ ] Fail
- [ ] Blocked

Actual result:

```text

```

Screenshot filename:

```text

```

### Test ADM-04 — Missing reason is denied

Steps:

1. Open the deactivation confirmation page.
2. Leave the reason empty.
3. Type `CONFIRM`.
4. Submit.

Expected:

- The request is refused.
- A clear error says that a reason is required.
- The owner remains active.
- The audit log contains `owner.deactivate` with outcome `denied`.
- Passwords and 2FA codes are not present in the audit entry.

Result:

- [X] Pass
- [ ] Fail
- [ ] Blocked

Actual result:

```text

```

Audit entry visible:

- [X] Yes
- [ ] No

### Test ADM-05 — Incorrect confirmation is denied

Steps:

1. Enter a meaningful reason.
2. Type something other than uppercase `CONFIRM`.
3. Submit.

Expected:

- The request is refused.
- The owner remains active.
- The error asks for `CONFIRM` exactly.
- The audit log records a `denied` attempt and its reason.

Result:

- [X] Pass
- [ ] Fail
- [ ] Blocked

Actual result:

```text

```

### Test ADM-06 — Successful deactivation

Before submitting, sign in as the disposable owner in another browser or
private window.

Steps:

1. As admin, enter a meaningful reason.
2. Type `CONFIRM`.
3. Submit the deactivation.
4. Refresh any page in the already-open owner session.
5. Attempt to sign in again as that owner.
6. Inspect `/admin/audit`.

Expected:

- The admin sees a success message.
- The owner status becomes `Inactive`.
- The existing owner session is invalidated on its next request.
- A new owner login is refused with the deactivated-account message.
- The audit record has:
  - action `owner.deactivate`;
  - the correct administrator;
  - the correct target email;
  - the entered reason;
  - outcome `success`.
- Before state `isActive: true` and after state `isActive: false` are stored
  internally and covered by the automated tests; they are not currently shown
  as columns in the audit table.

Result:

- [X] Pass
- [ ] Fail
- [ ] Blocked

Actual result:

```text

```

Screenshot filenames:

```text

```

### Test ADM-07 — Successful reactivation

Steps:

1. Select `Activate` for the same inactive owner.
2. Enter a meaningful reason.
3. Type `CONFIRM`.
4. Submit.
5. Attempt to sign in as the owner.

Expected:

- The owner status becomes `Active`.
- The audit log contains `owner.activate` with outcome `success`.
- Login is allowed, subject to normal email verification, trial, subscription,
  and 2FA rules.

Result:

- [X] Pass
- [ ] Fail
- [ ] Blocked

Actual result:

```text

```

## 4. Permanent deletion protection

Only use the disposable owner. Leave the successful deletion test until every
denial test is complete.

### Test ADM-08 — Deletion confirmation page

Steps:

1. Select `Delete account` for the disposable owner.

Expected:

- The page identifies the correct target.
- It explains Stripe cancellation, data removal, training-data handling, and
  the 30-day email block.
- It requests:
  - a reason;
  - the exact owner email;
  - uppercase `DELETE`;
  - the administrator password;
  - a six-digit 2FA code.
- No destructive request occurs before the form is submitted.

Result:

- [x] Pass
- [ ] Fail
- [ ] Blocked

Actual result:

```text

```

Screenshot filename:

```text

```

### Test ADM-09 — Wrong target email is denied

Steps:

1. Complete every field.
2. Enter a different email in the target-email field.
3. Submit.

Expected:

- Deletion is refused.
- The owner still exists and can be opened in admin.
- The audit log records `owner.delete` with outcome `denied`.

Result:

- [x] Pass
- [ ] Fail
- [ ] Blocked

Actual result:

```text

```

### Test ADM-10 — Wrong DELETE phrase is denied

Steps:

1. Enter the correct email.
2. Type `delete`, `Delete`, or another incorrect value instead of `DELETE`.
3. Complete the other fields and submit.

Expected:

- Deletion is refused.
- The owner still exists.
- The error requests uppercase `DELETE`.
- A denied audit entry is created.

Result:

- [X] Pass
- [ ] Fail
- [ ] Blocked

Actual result:

```text

```

### Test ADM-11 — Wrong administrator password is denied

Steps:

1. Enter the correct target email and `DELETE`.
2. Enter an incorrect administrator password.
3. Enter a current 2FA code.
4. Submit.

Expected:

- Deletion is refused.
- The owner still exists.
- The message reports an incorrect administrator password.
- The submitted password is never displayed or stored in the audit log.
- A denied audit entry is created.

Result:

- [X] Pass
- [ ] Fail
- [ ] Blocked

Actual result:

```text

```

### Test ADM-12 — Wrong or expired 2FA code is denied

Steps:

1. Complete all fields correctly except the 2FA code.
2. Submit a wrong or expired six-digit code.

Expected:

- Deletion is refused.
- The owner still exists.
- The message reports an invalid 2FA code.
- The submitted 2FA code is never displayed or stored in the audit log.
- A denied audit entry is created.

Result:

- [X] Pass
- [ ] Fail
- [ ] Blocked

Actual result:

```text

```

### Test ADM-13 — Successful permanent deletion

Prerequisites:

- Every previous deletion-denial test has been completed.
- The target is still the disposable owner.
- The target does not have a real paid Stripe subscription.

Steps:

1. Enter a meaningful reason.
2. Type the exact owner email.
3. Type uppercase `DELETE`.
4. Enter the correct administrator password.
5. Enter the current 2FA code.
6. Submit once.
7. Return to `/admin/owners`.
8. Inspect `/admin/audit`.
9. Attempt to sign in and register with the deleted email.

Expected:

- The owner disappears from the owner list.
- Account, businesses, menus, security settings, and subscription records are
  removed according to the deletion policy.
- The deleted email is blocked for 30 days.
- Sign-in or registration with that email shows the friendly blocked-email
  message and the unblock date.
- Reviewed training records may remain only after owner/business/menu links are
  removed.
- Unreviewed and failed training records are removed.
- The audit entry remains visible even though the owner was deleted.
- The audit entry has action `owner.delete` and outcome `success`.
- The administrator remains signed in.

Result:

- [ ] Pass
- [ ] Fail
- [X] Blocked

Actual result:

```text
bug entring every thing valid the screan desplayed a not clear error on the buttom left corner of the screan it is not clear the error number is 4XX 
TRYED TO GO BACK using the chrome errows ( <- ) the page is redirected to 404 page then returned home using the botten and the admin is dissconnected   
hear /admin there is no traice of the deleted owner 
hear /admin/owners there is no trace of it too 
hear /admin/audit there is with Outcome -> started
```

Screenshot filenames:

```text

```

### Test ADM-14 — Stripe cancellation in test mode

This test is optional and must only use Stripe test mode.

Steps:

1. Create a disposable owner with a Stripe test subscription.
2. Confirm that the test subscription is active in Stripe.
3. Complete the protected administrator deletion.
4. Inspect the test subscription in Stripe.

Expected:

- Stripe cancellation succeeds before local deletion.
- The local account is deleted only after successful Stripe cancellation.
- If Stripe cancellation fails, the local account remains available and the
  audit outcome is `failed`.

Result:

- [ ] Pass
- [ ] Fail
- [X] Not tested
- [ ] Blocked

Actual result:

```text

```

## 5. Scheduler

### Test SCH-01 — Schedule loads correctly

```powershell
php bin/console debug:scheduler --all
```

Expected:

- `subscription_reminders` is listed.
- The provider is `App\Message\SubscriptionDailyCheck`.
- The trigger is `every 1 day`.
- The next run is at `08:00`.
- There is no missing `cron-expression` error.

Result:

- [X] Pass
- [ ] Fail
- [ ] Blocked

Actual output:

```text

```

### Test SCH-02 — Development stack dry run

```powershell
.\dev.cmd up -DryRun
```

Expected:

- Symfony, FastAPI, ngrok, and Scheduler are listed.
- No service or terminal is actually started.
- The final message says the dry run completed.

Result:

- [X] Pass
- [ ] Fail
- [ ] Blocked

Actual output:

```text

```

### Test SCH-03 — Scheduler terminal and status

Only run this test when you are ready to start the complete local stack:

```powershell
.\dev.cmd up
```

After a few seconds:

```powershell
.\dev.cmd status
```

Expected:

- A separate visible Scheduler terminal appears.
- Its title is `S2S - Subscription Scheduler`.
- It consumes `scheduler_subscription_reminders`.
- `dev.cmd status` reports `Scheduler RUNNING`.
- Running `dev.cmd up` again does not create a duplicate Scheduler worker.

Result:

- [X] Pass
- [ ] Fail
- [ ] Blocked

Actual result:

```text

```

Screenshot filenames:

```text

```

### Test SCH-04 — Complete stack shutdown

```powershell
.\dev.cmd down
.\dev.cmd status
```

Expected:

- Symfony, FastAPI, ngrok, and Scheduler are stopped.
- No unrelated process is terminated.
- Scheduler reports `STOPPED`.

Result:

- [X] Pass
- [ ] Fail
- [ ] Blocked

Actual result:

```text

```

## 6. Mobile and usability checks

Test the confirmation and audit pages using browser responsive mode at
approximately `390 × 844`.

- [x] Sidebar can be opened and closed.
- [x] Audit table can be scrolled horizontally.
- [x] Confirmation fields remain fully visible.
- [x] Buttons do not overflow.
- [x] Password and 2FA fields use suitable mobile keyboards/autocomplete.
- [x] Error messages remain readable.
- [x] No icon name or broken font is displayed as plain text.
- [x] No accidental horizontal page overflow occurs outside the audit table.

Actual result / visual problems:

```text

```

Screenshot filenames:

```text

```

## 7. Final regression checklist

- [x] Normal administrator login still works.
- [x] Administrator 2FA still works.
- [x] Owner login still works for active accounts.
- [x] Deactivated owners are blocked.
- [x] Trial and subscription enforcement still works.
- [x] Owner self-service account deletion still works.
- [x] `/admin` dashboard still opens.
- [x] `/admin/owners` still opens.
- [x] Owner detail still displays businesses and menus.
- [?] Audit pagination works when more than 50 records exist, if testable.
- [ ] Symfony logs contain no new unexpected `ERROR` or `CRITICAL` entries.
- [x] Browser console contains no new JavaScript error.
- [x] Network panel contains no unexpected `500` response.

Regression notes:

```text
[Web Server ] Aug  3 10:19:10 |ERROR  | SERVER POST (500) /admin/owners/8/delete host="easiest-crescent-reveler.ngrok-free.dev" ip="127.0.0.1" scheme="https"
[PHP-CGI    ] {"message":"Matched route \"app_admin_owner_delete\".","context":{"route":"app_admin_owner_delete","route_parameters":{"_route":"app_admin_owner_delete","_controller":"App\\Controller\\AdminController::ownerDelete","id":"8"},"request_uri":"https://easiest-crescent-reveler.ngrok-free.dev/admin/owners/8/delete","method":"POST"},"level":200,"level_name":"INFO","channel":"request","datetime":"2026-08-03T10:19:09.648157+02:00","extra":{}}
```

## 8. Problem report template

Copy this section once for every separate problem.

### Problem ID: ISSUE-___

- Related test ID: ADM-13
- Severity:
  - [ ] Critical — security or destructive-data problem
  - [ ] High — feature unusable or repeated `500`
  - [x] Medium — incorrect behavior with a workaround
  - [ ] Low — visual or wording problem
- Page URL:
- Account type:
  - [X] Administrator
  - [ ] Owner
  - [ ] Logged out
- Browser/device: chrome / PC
- Time of failure: in this houer 

Expected result:

```text


```

Actual result:

```text

```

Exact reproduction steps:

```text
1.
2.
3.
```

Complete visible error message:

```text

```

Relevant Symfony terminal/log output:

```text

```

Relevant browser-console output:

```text

```

Screenshot or video filename:

```text

```

Does the problem happen every time?

- [ ] Yes
- [ ] No
- [X] Unknown

Did retrying change any account or data?

- [ ] No
- [ ] Yes — describe below
- [X] Unknown

Data-change description:

```text

```

## 9. Final feedback summary

Tests passed:

```text

```

Tests failed:

```text

```

Tests not performed:

```text

```

Most important problem:

```text

```

Can the admin changes be considered ready for continued development?

- [ ] Yes
- [X] Yes, after minor corrections
- [ ] No, important corrections are required
 

 over all we good just review ADM-13 see what is happening and fix it and lets move one 

## 10. Developer review after the test session

Review date: 03/08/2026

### Findings

- The ADM-13 deletion itself completed successfully: owner `8` and its linked
  application data were removed, and the 30-day email block was created.
- The HTTP `500` happened after the database deletion had committed, while the
  existing audit entry was being finalized through Doctrine's stale Unit of
  Work. This explains why the owner disappeared but audit entry `7` remained
  at `started`.
- The schema-validation failure was caused by index, foreign-key, and datetime
  metadata names that differed from the current Doctrine mapping.
- All other completed manual checks passed. ADM-14 remains intentionally
  untested because it requires a Stripe test subscription.

### Corrections applied

- Persisted audit entries are now finalized with an isolated DBAL update.
- An audit-finalization failure is logged but can no longer turn an already
  completed destructive operation into an HTTP `500` response.
- Account-deletion rollback is attempted only while a transaction is active.
- Best-effort local/FastAPI storage cleanup runs after the database transaction
  has committed.
- Migration `Version20260803113000` synchronizes the database schema with the
  Doctrine mapping.
- Historical audit entry `7` was reconciled from `started` to `success`.
- The test-only block for `firmaagritech@gmail.com` was removed directly from
  the database; no user currently exists with that email, so it can be
  registered again.

### Focused retest still required

- [ ] Register `firmaagritech@gmail.com` again and verify registration is
  accepted.
- [ ] Repeat ADM-13 once with that disposable account.
- [ ] Confirm the response redirects to `/admin/owners` without a `500`.
- [ ] Confirm the new audit entry immediately shows outcome `success`.
- [ ] Confirm the deleted email is blocked again for 30 days.
- [ ] Confirm the administrator remains signed in.

Retest notes / problem description:

```text

```
