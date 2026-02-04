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
= 1.0.0 =
* Початковий реліз.
