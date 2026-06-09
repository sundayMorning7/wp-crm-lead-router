=== LeadRouter by Maks Devda ===
Contributors: maksdevda
Tags: leads, crm, routing, partners, distribution
Requires at least: 5.5
Tested up to: 6.6
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

LeadRouter: розподіл лідів між партнерами за групами. Включає CPT для груп і партнерів, логи призначень та адмін-сторінку.

== Опис ==
- CPT: leadrouter_group, leadrouter_partner
- Прив’язка партнера до групи через метаполе
- Функція `leadrouter_assign_lead( $group_id, $lead_id = 0 )`
- Логування в таблицю `{prefix}_leadrouter_logs`
- Сторінка "Логи розподілу" в адмінці
- "Налаштування" з вибором групи за замовчуванням

== Встановлення ==
1. Завантажте zip і встановіть як плагін.
2. Активуйте плагін.
3. Створіть кілька груп та партнерів (у меню LeadRouter).
4. Прив’яжіть партнерів до груп.
5. Використовуйте `leadrouter_assign_lead( $group_id, $lead_id )` у вашому коді.

== Changelog ==
= 1.4.3 =
* Фінальна очистка: видалено колонки billing_paused / paused_reason / paused_at з таблиці leadrouter_partner_billing (увімкнено міграцію leadrouter_billing_db_migrate, DROP COLUMN).
* Перехід на модель active/deactivated (deactivated_by_billing) завершено; косметичні перейменування (мітка deactivated_partner, CSS lr-badge--deactivated).

= 1.4.2 =
* Нові поля білінгу партнера: email, allow_negative_balance, disable_low_balance_email, deactivated_by_billing та прапорці сповіщень (notified_low_balance, notified_stopped, notified_admin_negative).
* Прибрано механізм паузи: видалено колонки billing_paused / paused_reason / paused_at (міграція leadrouter_billing_db_migrate). Замість паузи — deactivated_by_billing.

= 1.4.0 =
* Білінг партнера вбудовано у сторінку партнера як вкладки Carbon Fields (Білінг + Білінг history).
* Налаштування та ручні операції — у 2 колонки; історія (транзакції/audit/помилки) — окрема вкладка з AJAX-пагінацією.
* Неймспейснуто DOM-імена полів білінгу (lr_bil_*), щоб не засмічувати $_POST при збереженні поста партнера.

= 1.3.0 =
* Модуль білінгу партнерів: баланс, транзакції, audit trail та інтеграція зі Stripe.
* Realtime списання балансу після успішного відправлення ліда (хук leadrouter_after_send).
* Cron кожні 2 хв: перевірка балансів і Stripe auto-charge при balance < min_balance.

= 1.0.0 =
* Початковий реліз.
