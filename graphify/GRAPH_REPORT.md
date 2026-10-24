# Graph Report - repo-caffenet  (2026-10-23)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 2293 nodes · 4938 edges · 163 communities (74 shown, 89 thin omitted)
- Extraction: 97% EXTRACTED · 3% INFERRED · 0% AMBIGUOUS · INFERRED: 142 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `8eb8c416`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- Community 0
- Community 1
- Community 2
- Community 3
- Community 4
- Community 5
- Community 6
- Community 7
- Community 8
- Community 9
- Community 10
- Community 11
- Community 12
- Community 13
- Community 14
- Community 15
- Community 16
- Community 17
- Community 18
- Community 19
- Community 20
- Community 21
- Community 22
- Community 23
- Community 24
- Community 25
- Community 26
- Community 27
- Community 28
- Community 29
- Community 30
- Community 31
- Community 32
- Community 33
- Community 34
- Community 35
- Community 36
- Community 37
- Community 38
- Community 39
- Community 40
- Community 41
- Community 42
- Community 44
- Community 45
- Community 46
- Community 47
- Community 48
- Community 49
- Community 50
- Community 51
- Community 52
- Community 53
- Community 54
- Community 55
- Community 56
- Community 57
- Community 58
- Community 59
- Community 60
- Community 61
- Community 62
- Community 63
- Community 64
- Community 65
- Community 66
- Community 67
- Community 68
- Community 69
- Community 70
- Community 71
- Community 72
- Community 73
- Community 74
- Community 75
- Community 76
- Community 77
- Community 78
- Community 79
- Community 80
- Community 81
- Community 82
- Community 83
- Community 84
- Community 85
- Community 86
- Community 87
- Community 88
- Community 89
- Community 90
- Community 91
- Community 92
- Community 93
- Community 94
- Community 95
- Community 96
- Community 97
- Community 98
- Community 99
- Community 100
- Community 101
- Community 102
- Community 103
- Community 104
- Community 105
- Community 106
- Community 107
- Community 108
- Community 109
- Community 110
- Community 111
- Community 112
- Community 113
- Community 114
- Community 116
- Community 118
- Community 120
- Community 121
- Community 122
- Community 123
- Community 124
- Community 125
- Community 126
- Community 127
- Community 128
- Community 129
- Community 130
- Community 132
- Community 133
- Community 135
- Community 136
- Community 137
- Community 138
- Community 139
- Community 143
- Community 144
- Community 145
- Community 148

## God Nodes (most connected - your core abstractions)
1. `Order` - 157 edges
2. `User` - 77 edges
3. `Api` - 64 edges
4. `TestCase` - 56 edges
5. `SettingsService` - 51 edges
6. `Controller` - 41 edges
7. `CaffenetModel` - 27 edges
8. `JalaliDatePicker` - 26 edges
9. `QueueManagerServiceInterface` - 25 edges
10. `NotificationDispatcherServiceInterface` - 25 edges

## Surprising Connections (you probably didn't know these)
- `BazaarIapSettingsResolver` --implements--> `BazaarIapSettings`  [EXTRACTED]
  app/Services/BazaarIapSettingsResolver.php → packages/caffenet/bazaar-iap/src/Contracts/BazaarIapSettings.php
- `{closure#3}()` --calls--> `BazaarIap`  [INFERRED]
  packages/caffenet/bazaar-iap/src/BazaarIapServiceProvider.php → packages/caffenet/bazaar-iap/src/BazaarIap.php
- `{closure#6}()` --references_constant--> `Order`  [EXTRACTED]
  app/Services/Queue/QueueManagerService.php → app/Models/Order.php
- `{closure#8}()` --references_constant--> `Order`  [EXTRACTED]
  app/Services/Queue/QueueManagerService.php → app/Models/Order.php
- `{closure#1}()` --calls--> `ConfigBazaarIapSettings`  [EXTRACTED]
  packages/caffenet/bazaar-iap/src/BazaarIapServiceProvider.php → packages/caffenet/bazaar-iap/src/Support/ConfigBazaarIapSettings.php

## Import Cycles
- None detected.

## Communities (163 total, 89 thin omitted)

### Community 1 - "Community 1"
Cohesion: 0.05
Nodes (62): CLOSE_BTN_ELM_QUERY, CLOSE_BUTTON_ELEMENT_QUERY, CONTAINER_ELM_QUERY, DAY_DISABLED_ELEMENT_QUERY, DAY_DISABLED_ELM_QUERY, DAY_DISABLED_NOT_IN_MONTH_ELEMENT_QUERY, DAY_DISABLED_NOTINMONTH_ELM_QUERY, DAY_ELEMENT_QUERY (+54 more)

### Community 2 - "Community 2"
Cohesion: 0.07
Nodes (38): Ae(), B(), Be(), c(), $e(), ee(), F(), fe() (+30 more)

### Community 3 - "Community 3"
Cohesion: 0.09
Nodes (7): AuthController, CatalogController, SettingsController, SliderController, SmsController, SmsPatternVarController, UserController

### Community 4 - "Community 4"
Cohesion: 0.06
Nodes (13): AdminSet, CaffenetCleanup, DbStatus, QueueSlotsGenerate, CleanupService, SlugService, up(), AdminUsersSeeder (+5 more)

### Community 5 - "Community 5"
Cohesion: 0.09
Nodes (4): UserPurgeService, ExampleTest, GuestCatalogAccessTest, TestCase

### Community 6 - "Community 6"
Cohesion: 0.08
Nodes (36): {closure#1}(), {closure#10}(), {closure#11}(), {closure#12}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}() (+28 more)

### Community 7 - "Community 7"
Cohesion: 0.07
Nodes (36): ATTR_OPTION_NAMES, DEFAULT_DAYS, DEFAULT_MINUS_HTML, DEFAULT_MONTHS, DEFAULT_MULTIPLE_SEPARATOR, DEFAULT_PLUS_HTML, DEFAULT_RANGE_SEPARATOR, DEFAULT_SEPARATOR_CHARS (+28 more)

### Community 8 - "Community 8"
Cohesion: 0.06
Nodes (28): CONTAINER_ELEMENT_QUERY, EVENT_FOCUS_STR, EVENT_KEYDOWN_STR, OVERLAY_ELEMENT_QUERY, STYLE_DISPLAY_BLOCK, STYLE_DISPLAY_HIDDEN, STYLE_POSITION_FIXED, STYLE_VISIBILITY_HIDDEN (+20 more)

### Community 9 - "Community 9"
Cohesion: 0.08
Nodes (7): ArtisanRunnerController, DashboardController, PermissionController, QueueController, TrackController, UploadController, Controller

### Community 10 - "Community 10"
Cohesion: 0.11
Nodes (5): OrderLifecycleService, Order, NotificationDispatcherService, {closure#2}(), {closure#3}()

### Community 11 - "Community 11"
Cohesion: 0.14
Nodes (32): getInitialDate(), setMultipleDateSelection(), areSameDates(), escapeRegex(), getConvertedValue(), getDateNumber(), getDateOnlyValueStringWithSep(), getDatePartsFromString() (+24 more)

### Community 12 - "Community 12"
Cohesion: 0.06
Nodes (11): {closure#1}(), {closure#1}(), {closure#2}(), {closure#1}(), {closure#2}(), {closure#3}(), {closure#1}(), {closure#1}() (+3 more)

### Community 13 - "Community 13"
Cohesion: 0.09
Nodes (19): collectPresets(), S, openBusinessSettings(), openCoupons(), couponForm(), render(), openSliderManager(), render() (+11 more)

### Community 14 - "Community 14"
Cohesion: 0.06
Nodes (30): BazaarIap, autoload, autoload-dev, psr-4, psr-4, config, sort-packages, description (+22 more)

### Community 15 - "Community 15"
Cohesion: 0.11
Nodes (20): dashboardStatsHtml(), S, loadLogs(), openCategoryForm(), iconFieldHtml(), previewBox(), updateColorPreview(), openServiceForm() (+12 more)

### Community 16 - "Community 16"
Cohesion: 0.11
Nodes (8): {closure#1}(), WalletController, OrderLog, WalletTransaction, {closure#1}(), {closure#1}(), {closure#2}(), WalletService

### Community 17 - "Community 17"
Cohesion: 0.10
Nodes (3): {closure#3}(), FortifyLockoutResponse, FortifyLoginResponse

### Community 19 - "Community 19"
Cohesion: 0.18
Nodes (23): renderDatePicker(), renderDays(), render(), getArrayNumbersStringTo(), getMaxTime(), getMinTime(), getMinuteItems(), getSecondItems() (+15 more)

### Community 20 - "Community 20"
Cohesion: 0.11
Nodes (3): QueueManagerServiceInterface, AutoRequeueService, OrderLifecycleService

### Community 22 - "Community 22"
Cohesion: 0.12
Nodes (3): {closure#2}(), {closure#3}(), RoleSeeder

### Community 23 - "Community 23"
Cohesion: 0.08
Nodes (20): devDependencies, concurrently, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite, vite, optionalDependencies (+12 more)

### Community 24 - "Community 24"
Cohesion: 0.11
Nodes (5): StatusResolverServiceInterface, WaitTimeEstimatorServiceInterface, {closure#1}(), StatusResolverService, {closure#1}()

### Community 25 - "Community 25"
Cohesion: 0.17
Nodes (5): PaymentController, FulfillBazaarPurchase, BazaarPayContract, Payment, PaymentFulfillmentService

### Community 26 - "Community 26"
Cohesion: 0.15
Nodes (5): PurchaseConsumed, PurchaseFailed, BazaarIapController, BazaarIapTransaction, {closure#1}()

### Community 27 - "Community 27"
Cohesion: 0.11
Nodes (10): createElementMinus(), createElementPlus(), createElementPlusMinus(), getInputValue(), renderMonths(), renderYear(), renderFooter(), JalaliDatePicker (+2 more)

### Community 28 - "Community 28"
Cohesion: 0.13
Nodes (3): OtpCode, User, RequestModeDurationTest

### Community 31 - "Community 31"
Cohesion: 0.10
Nodes (8): {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), {closure#6}(), {closure#7}(), GET /test-gateway(), GET /uploads/{filename}()

### Community 32 - "Community 32"
Cohesion: 0.09
Nodes (22): description, homepage, vite, keywords, license, main, name, version (+14 more)

### Community 33 - "Community 33"
Cohesion: 0.14
Nodes (4): AutoRequeueServiceInterface, NotificationDispatcherServiceInterface, LogNotificationOnStart, RequeueOnNoResponse

### Community 35 - "Community 35"
Cohesion: 0.09
Nodes (21): compilerOptions, allowJs, allowSyntheticDefaultImports, declaration, esModuleInterop, forceConsistentCasingInFileNames, isolatedModules, jsx (+13 more)

### Community 36 - "Community 36"
Cohesion: 0.11
Nodes (12): bindSheetWatchdog(), carouselDrag(), onMove(), onUp(), iconPicker(), renderBody(), renderGrid(), imageSlider() (+4 more)

### Community 37 - "Community 37"
Cohesion: 0.17
Nodes (5): SlotReservationServiceInterface, CapacitySlot, {closure#2}(), {closure#4}(), SlotReservationService

### Community 39 - "Community 39"
Cohesion: 0.28
Nodes (4): CheckNoResponseOrders, CleanupExpiredLocks, RefreshCapacitySnapshot, SendStartReminder

### Community 41 - "Community 41"
Cohesion: 0.19
Nodes (6): OrderCancelled, OrderCompleted, OrderCreated, OrderNoResponse, OrderRequeued, OrderStarted

### Community 45 - "Community 45"
Cohesion: 0.25
Nodes (7): PermissionService, {closure#1}(), up(), up(), up(), up(), up()

### Community 46 - "Community 46"
Cohesion: 0.20
Nodes (18): collectFilters(), loadHead(), loadList(), loadUsers(), openManualPayment(), openOrderDetail(), openOrderRefund(), openRefund() (+10 more)

### Community 47 - "Community 47"
Cohesion: 0.13
Nodes (5): {closure#1}(), {closure#2}(), Formatter, Coupon, Service

### Community 48 - "Community 48"
Cohesion: 0.18
Nodes (8): _browserNotify(), _formatDuration(), _formatTime(), _maybeNotify(), Poller, _renderCapacity(), startCapacityIndicator(), startOrderTracking()

### Community 50 - "Community 50"
Cohesion: 0.12
Nodes (17): devDependencies, @babel/cli, @babel/core, @babel/preset-env, eslint, eslint-config-prettier, jsdom, sass (+9 more)

### Community 51 - "Community 51"
Cohesion: 0.27
Nodes (4): {closure#1}(), {closure#2}(), {closure#1}(), AuthService

### Community 52 - "Community 52"
Cohesion: 0.13
Nodes (7): CaffenetModel, {closure#1}(), QueueLock, SliderImage, SmsLog, SmsPatternVar, TicketMessage

### Community 53 - "Community 53"
Cohesion: 0.29
Nodes (15): applyRoute(), boot(), defaultScreen(), enablePushNotifications(), handleAppBarAction(), handlePaymentReturn(), isAdminScreen(), navigate() (+7 more)

### Community 54 - "Community 54"
Cohesion: 0.24
Nodes (4): EnsureAuth, OptionalAuth, OriginGuard, SecurityHeaders

### Community 55 - "Community 55"
Cohesion: 0.17
Nodes (3): NotificationLog, OrderStatusHistory, QueueEntry

### Community 56 - "Community 56"
Cohesion: 0.15
Nodes (5): {closure#10}(), {closure#13}(), {closure#2}(), {closure#6}(), {closure#8}()

### Community 58 - "Community 58"
Cohesion: 0.26
Nodes (11): bySortOrder(), draftsSectionHtml(), gotoService(), homeServicesHtml(), openSearchSheet(), renderResults(), popularServices(), relativeAgo() (+3 more)

### Community 59 - "Community 59"
Cohesion: 0.37
Nodes (14): esc(), fa(), formatDateTime(), formatDuration(), formatTime(), hashOf(), load(), noticesHtml() (+6 more)

### Community 64 - "Community 64"
Cohesion: 0.19
Nodes (3): CapacityController, CapacityResource, OrderResource

### Community 69 - "Community 69"
Cohesion: 0.17
Nodes (4): EVENT_CHANGE_INPUT_STR, loadDatepicker(), Window, vitest

### Community 70 - "Community 70"
Cohesion: 0.29
Nodes (11): avgRating(), filteredOrders(), openDetail(), applyOrder(), renderCustomerFiles(), renderResultFiles(), orderFormDataRows(), renderList() (+3 more)

### Community 76 - "Community 76"
Cohesion: 0.27
Nodes (4): {closure#1}(), {closure#2}(), {closure#3}(), BazaarPurchaseClient

### Community 77 - "Community 77"
Cohesion: 0.35
Nodes (8): enable(), _ensureMessaging(), _getSwRegistration(), init(), _initMessaging(), _loadFirebaseSdk(), _registerToken(), requestPermission()

### Community 80 - "Community 80"
Cohesion: 0.20
Nodes (9): description, keywords, license, minimum-stability, name, prefer-stable, repositories, $schema (+1 more)

### Community 81 - "Community 81"
Cohesion: 0.22
Nodes (7): {closure#1}(), {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), ensure(), up()

### Community 83 - "Community 83"
Cohesion: 0.20
Nodes (10): scripts, build, build:minified, build:unminified, convert-dist-es5, dev, lint, prepublishOnly (+2 more)

### Community 84 - "Community 84"
Cohesion: 0.20
Nodes (9): bracketSameLine, htmlWhitespaceSensitivity, overrides, printWidth, semi, singleQuote, tabWidth, trailingComma (+1 more)

### Community 85 - "Community 85"
Cohesion: 0.29
Nodes (6): bridge(), getAccountId(), installBackHandler(), isInBazaar(), login(), pay()

### Community 86 - "Community 86"
Cohesion: 0.49
Nodes (9): clear(), latestForUser(), listForUser(), load(), pick(), readStore(), safeStorage(), save() (+1 more)

### Community 91 - "Community 91"
Cohesion: 0.22
Nodes (9): scripts, dev, post-autoload-dump, post-create-project-cmd, post-root-package-install, post-update-cmd, pre-package-uninstall, setup (+1 more)

### Community 93 - "Community 93"
Cohesion: 0.33
Nodes (6): bridge(), getAccountId(), installBackHandler(), isInBazaar(), login(), pay()

### Community 94 - "Community 94"
Cohesion: 0.42
Nodes (8): fieldInput(), livePricing(), openRequestConfirmation(), renderFiles(), renderForm(), renderPrice(), sameValue(), submitOrder()

### Community 95 - "Community 95"
Cohesion: 0.42
Nodes (8): isTyping(), load(), messageHtml(), queueStatusCardHtml(), refresh(), render(), renderMessages(), send()

### Community 97 - "Community 97"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pao, laravel/pint, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 99 - "Community 99"
Cohesion: 0.43
Nodes (6): getDaysInMonth(), getWeekDay(), isLeapYear(), jalaliToday(), toJalali(), mod()

### Community 100 - "Community 100"
Cohesion: 0.36
Nodes (5): has(), hasAny(), resolve(), roleDefaults(), satisfies()

### Community 106 - "Community 106"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 107 - "Community 107"
Cohesion: 0.29
Nodes (7): require, caffenet/bazaar-iap, laravel/fortify, laravel/framework, laravel/tinker, php, spatie/laravel-permission

### Community 108 - "Community 108"
Cohesion: 0.29
Nodes (4): {closure#1}(), {closure#3}(), {closure#4}(), {closure#5}()

### Community 114 - "Community 114"
Cohesion: 0.33
Nodes (3): {closure#1}(), {closure#2}(), {closure#3}()

### Community 118 - "Community 118"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 135 - "Community 135"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Tests\\

### Community 136 - "Community 136"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

### Community 137 - "Community 137"
Cohesion: 0.67
Nodes (3): bugs, email, url

### Community 138 - "Community 138"
Cohesion: 0.67
Nodes (3): repository, type, url

## Knowledge Gaps
- **19 isolated node(s):** `vite`, `concurrently`, `@laravel/multiplex`, `tailwindcss`, `vite` (+14 more)
  These have ≤1 connection - possible missing edges. (Counts symbols only; 598 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **89 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `Community 28` to `Community 3`, `Community 5`, `Community 9`, `Community 16`, `Community 17`, `Community 22`, `Community 25`, `Community 29`, `Community 34`, `Community 47`, `Community 51`, `Community 52`, `Community 54`, `Community 60`, `Community 71`, `Community 78`, `Community 79`, `Community 87`, `Community 96`, `Community 98`, `Community 101`, `Community 105`, `Community 110`, `Community 112`?**
  _High betweenness centrality (0.067) - this node is a cross-community bridge._
- **What connects `vite`, `concurrently`, `@laravel/multiplex` to the rest of the system?**
  _19 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Community 0` be split into smaller, more focused modules?**
  _Cohesion score 0.06648575305291723 - nodes in this community are weakly interconnected._
- **Why does `Order` connect `Community 10` to `Community 5`, `Community 9`, `Community 16`, `Community 18`, `Community 20`, `Community 24`, `Community 25`, `Community 28`, `Community 29`, `Community 31`, `Community 33`, `Community 34`, `Community 37`, `Community 39`, `Community 41`, `Community 47`, `Community 49`, `Community 52`, `Community 55`, `Community 56`, `Community 60`, `Community 63`, `Community 66`, `Community 67`, `Community 71`, `Community 72`, `Community 73`, `Community 87`, `Community 103`, `Community 104`, `Community 105`?**
  _High betweenness centrality (0.065) - this node is a cross-community bridge._
- **Should `Community 1` be split into smaller, more focused modules?**
  _Cohesion score 0.04567307692307692 - nodes in this community are weakly interconnected._
- **Why does `SettingsService` connect `Community 38` to `Community 65`, `Community 34`, `Community 3`, `Community 4`, `Community 5`, `Community 10`, `Community 42`, `Community 44`, `Community 47`, `Community 17`, `Community 51`, `Community 21`, `Community 54`, `Community 25`, `Community 90`, `Community 29`, `Community 30`, `Community 31`?**
  _High betweenness centrality (0.031) - this node is a cross-community bridge._
- **Should `Community 2` be split into smaller, more focused modules?**
  _Cohesion score 0.07467532467532467 - nodes in this community are weakly interconnected._