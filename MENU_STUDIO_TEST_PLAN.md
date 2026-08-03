# Menu Studio Test Plan

Branch: `feat/menu-studio`

Use this checklist with an owner account that has at least one draft menu and one published menu. Hard-refresh the browser once before testing so the updated CSS and JavaScript are loaded.

For every failed check, record the device/browser, URL, exact action, expected result, actual result, and a screenshot or Symfony log excerpt.

## 1. Public mobile stability

- [ ] **PUB-01 — Normal scroll:** Open a published menu on a phone and slowly scroll from the top to the bottom. The business header should leave the screen naturally and the category rail should remain stable at the top.
- [ ] **PUB-02 — Fast scroll:** Flick rapidly up and down. The header and category rail must not flicker, overlap, or repeatedly change height.
- [ ] **PUB-03 — Category tracking:** Scroll through several categories. The active category changes only when the visible section changes and the rail does not shake horizontally.
- [ ] **PUB-04 — Category click:** Tap a category pill. The correct section scrolls below the sticky rail.
- [ ] **PUB-05 — Reduced motion:** Enable reduced motion on the device. The menu remains usable without unnecessary animations.

Problem notes:

```
Check ID:
Device/browser:
URL:
Expected:
Actual:
Screenshot/log:
```

## 2. Workspace layout

- [ ] **WS-01 — Desktop:** Content tools appear in the left workspace rail and the menu content remains readable without horizontal page overflow.
- [ ] **WS-02 — Tablet:** The workspace tool rail becomes a horizontal sticky toolbar.
- [ ] **WS-03 — Phone portrait:** Items display as cards instead of a 520px-wide table. All actions remain reachable.
- [ ] **WS-04 — Navigation:** Content, Appearance, Live preview, QR & share, and AI scanner open the correct destination.
- [ ] **WS-05 — Global icons:** Dashboard, Businesses, Menus, and Account show consistent Material icons and active states.

Problem notes:

```
Check ID:
Viewport/device:
Expected:
Actual:
Screenshot:
```

## 3. Content controls

- [ ] **CNT-01 — Drag categories:** Reorder categories on desktop and refresh. The saved order remains.
- [ ] **CNT-02 — Drag items:** Reorder items inside one category and refresh. The saved order remains.
- [ ] **CNT-03 — Mobile movement:** Use the up/down controls on a phone. The order changes and remains after refresh.
- [ ] **CNT-04 — Duplicate item:** Duplicate an item. The copy retains its price, image reference, labels, variants, and availability.
- [ ] **CNT-05 — Duplicate category:** Duplicate a category. Its items are copied and the source remains unchanged.
- [ ] **CNT-06 — Visibility:** Hide/show a category. The public menu updates accordingly.
- [ ] **CNT-07 — Availability:** Mark an item unavailable/available. The public menu updates accordingly.
- [ ] **CNT-08 — Ownership:** Try a copied reorder/duplicate URL belonging to another owner while logged into a different account. It must return 404 or access denied and change nothing.
- [ ] **CNT-09 — Expired CSRF:** Submit an old page after logging out/in. A friendly security message should appear and no change should be made.

Problem notes:

```
Check ID:
Menu/category/item:
Action:
Expected:
Actual:
Symfony log:
```

## 4. Appearance Studio

- [ ] **DES-01 — Desktop preview:** Open Appearance and switch preview between desktop, tablet, and phone widths.
- [ ] **DES-02 — Mobile views:** On a phone, switch between Controls and Preview. Portrait mode must remain usable; landscape is optional.
- [ ] **DES-03 — Presets:** Apply Classic, Café, and Night. Each updates the preview immediately.
- [ ] **DES-04 — Contrast warning:** Choose similar header and accent colors. A low-contrast warning appears.
- [ ] **DES-05 — Save:** Save a design, refresh the public preview, and confirm it persists.
- [ ] **DES-06 — Unsaved close:** Change a value and close the studio. A discard confirmation appears.
- [ ] **DES-07 — Failed replacement:** Upload an invalid background image. The previous background remains available.
- [ ] **DES-08 — Invalid values:** Manually alter theme POST values through browser tools. Unsupported fonts, directions, and colors fall back to safe values.

Problem notes:

```
Check ID:
Preset/control:
Expected:
Actual:
Screenshot/network response:
```

## 5. Publishing safeguards

- [ ] **PUBS-01 — Empty menu:** Publishing an empty menu is refused with instructions to add visible content.
- [ ] **PUBS-02 — Hidden content:** A menu containing only hidden categories or unavailable items cannot be newly published.
- [ ] **PUBS-03 — Valid menu:** A menu with a visible category and available item publishes successfully.
- [ ] **PUBS-04 — Plan limit:** Publishing beyond the account limit shows the plan-specific message and changes nothing.
- [ ] **PUBS-05 — Unpublish:** Moving a menu to draft removes public access while preserving all content.
- [ ] **PUBS-06 — Live warning:** A published menu clearly says saved content/design changes are immediately public.

Problem notes:

```
Check ID:
Account plan:
Menu status/content:
Expected:
Actual:
```

## 6. Rich item options

- [ ] **ITEM-01 — Details:** Save a detailed description and confirm it appears in the customer item sheet.
- [ ] **ITEM-02 — Badge:** Save each supported badge and confirm it appears on the card and item sheet.
- [ ] **ITEM-03 — Labels:** Save dietary tags and allergens with duplicate/mixed-case input. Labels are deduplicated and safely displayed.
- [ ] **ITEM-04 — Variants:** Add multiple named prices. They appear in the public item sheet using the selected currency.
- [ ] **ITEM-05 — Validation:** Leave half of a variant row empty. The modal shows a useful error and does not partially save.
- [ ] **ITEM-06 — Availability note:** Save an availability note and confirm it appears in the item sheet.
- [ ] **ITEM-07 — Existing items:** Open several pre-migration items. They render normally with no empty labels or JavaScript errors.

Problem notes:

```
Check ID:
Item:
Input:
Expected:
Actual:
Screenshot/console:
```

## 7. Load and regression

- [ ] **LOAD-01 — Large menu:** Test at least 20 categories and 200 items. Scrolling and opening item details remain responsive.
- [ ] **LOAD-02 — Images:** Item images reserve their dimensions and do not cause cards to jump while loading.
- [ ] **LOAD-03 — Fonts:** The public page downloads only Space Grotesk plus the selected menu font.
- [ ] **LOAD-04 — Currency:** Navigate between menus in one session. Exchange rates are reused for six hours instead of fetched on every page.
- [ ] **LOAD-05 — Subscription gate:** Expire or block a test owner. Public menus become inaccessible immediately; no stale HTML menu is served.
- [ ] **REG-01 — Scanner:** AI scanning remains restricted to an empty menu.
- [ ] **REG-02 — QR:** Existing permanent QR links still resolve to the correct business/menu flow.
- [ ] **REG-03 — Subscription quotas:** Draft and published limits still match Basic, Premium, Pro, and trial entitlements.

Problem notes:

```
Check ID:
Dataset/account:
Expected:
Actual:
Timing/network/database notes:
```

## Automated verification commands

Run from `my_project_directory`:

```powershell
php bin/phpunit
php bin/console lint:twig templates
php bin/console lint:container
php bin/console doctrine:schema:validate
php bin/console doctrine:migrations:status
git diff --check
```

Expected results: all tests pass, all Twig templates are valid, the container is valid, Doctrine mapping and schema are synchronized, no migrations are pending, and `git diff --check` reports no errors.
