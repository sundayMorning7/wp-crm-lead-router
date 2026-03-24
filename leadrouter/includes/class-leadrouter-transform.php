<?php
if (!class_exists('LeadRouter_Transform')) {
    class LeadRouter_Transform {

        /**
         * Застосувати трансформацію до значення.
         * @param mixed $value
         * @param string $transform
         * @return mixed|null
         */
        public static function apply($value, string $transform) {
            if ($value === null) return null;

            // Перетворюємо в string, якщо це не масив
            if (is_array($value) || is_object($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE);
            } else {
                $value = (string)$value;
            }

            switch ($transform) {
                case 'none':
                default:
                    return $value;

                // 🧰 базові трансформації
                case 'lower':    return mb_strtolower($value, 'UTF-8');
                case 'upper':    return mb_strtoupper($value, 'UTF-8');
                case 'title':    return self::mb_ucwords(mb_strtolower($value, 'UTF-8'));
                case 'digits':   return preg_replace('/\D+/', '', $value);
                case 'int':      return self::toIntOrNull($value);
                case 'float2':   return self::toFloat2OrNull($value);

                // 🕒 дати
                case 'date_Ymd':      return self::dateFormatFlexible($value, 'Y-m-d');
                case 'date_mdy':      return self::dateFormatFlexible($value, 'm/d/Y');
                case 'date_mdy_dash': return self::dateFormatFlexible($value, 'm-d-Y'); // НОВЕ

                // 📞 телефон
                case 'phone_us_dashed': return self::phoneUsDashed($value); // НОВЕ

                // 🧠 розділення name на first/last
                case 'split_name_fn': return self::splitName($value, 'fn');
                case 'split_name_ln': return self::splitName($value, 'ln');

                // 🚗 специфічна логіка стану авто
                case 'map_running':    return self::mapRunning($value);
                case 'inop_binary':    return self::inopBinary($value);
            }
        }

        /** Phone → 111-111-1111 */
        protected static function phoneUsDashed(?string $raw): ?string {
            if ($raw === null || $raw === '') return null;
            $digits = preg_replace('/\D+/', '', $raw);
            if ($digits === '') return null;

            // Якщо 11 і перша '1' — відкинути
            if (strlen($digits) === 11 && $digits[0] === '1') {
                $digits = substr($digits, 1);
            }
            // Якщо більше 10 — беремо останні 10
            if (strlen($digits) > 10) {
                $digits = substr($digits, -10);
            }

            if (strlen($digits) !== 10) return null;
            return substr($digits,0,3) . '-' . substr($digits,3,3) . '-' . substr($digits,6,4);
        }

        /** Розумне форматування дати */
        protected static function dateFormatFlexible(?string $raw, string $outFormat): ?string {
            if ($raw === null) return null;
            $raw = trim($raw);
            if ($raw === '') return null;

            $norm = preg_replace('/[^\d]+/u', ' ', $raw);
            $norm = trim(preg_replace('/\s+/', ' ', $norm));
            $parts = $norm === '' ? [] : explode(' ', $norm);

            if (count($parts) < 3) {
                $ts = self::createDT($raw);
                return $ts ? $ts->format($outFormat) : null;
            }

            $a = (int)$parts[0];
            $b = (int)$parts[1];
            $c = (int)$parts[2];

            $y = $m = $d = null;

            if (strlen($parts[0]) === 4) { // Y M D
                $y = $a; $m = $b; $d = $c;
            } elseif (strlen($parts[2]) === 4) { // M D Y
                $y = $c; $m = $a; $d = $b;
            } else { // YY
                $y = self::expandYear2((int)$parts[2]);
                $m = $a; $d = $b;
            }

            if (!self::isValidDate($y, $m, $d) && $m > 12 && $d <= 12) {
                $tmp = $m; $m = $d; $d = $tmp;
            }

            if (!self::isValidDate($y, $m, $d)) {
                $ts = self::createDT($raw);
                return $ts ? $ts->format($outFormat) : null;
            }

            $dt = DateTime::createFromFormat('!Y-n-j', sprintf('%04d-%d-%d', $y, $m, $d));
            return $dt ? $dt->format($outFormat) : null;
        }

        /** map_running: Running → operable */
        protected static function mapRunning(?string $value): ?string {
            $v = mb_strtolower(trim((string)$value));
            if ($v === 'running') return 'operable';
            if ($v === 'nonrunning' || $v === 'non-running') return 'inoperable';
            return $value;
        }

        /** inop_binary: Running → 0, інше → 1 */
        protected static function inopBinary(?string $value): ?string {
            $v = mb_strtolower(trim((string)$value));
            if ($v === 'running' || $v === '0') return '0';
            return '1';
        }

        /** split_name_fn / split_name_ln */
        protected static function splitName(?string $value, string $part): ?string {
            if ($value === null) return null;
            $parts = preg_split('/\s+/', trim($value));
            if (!$parts || count($parts) === 0) return null;
            if ($part === 'fn') return $parts[0];
            array_shift($parts);
            return count($parts) ? implode(' ', $parts) : '';
        }

        /** утиліти */
        protected static function mb_ucwords(string $str): string {
            return preg_replace_callback('/\b\p{L}/u', function($m) {
                return mb_strtoupper($m[0], 'UTF-8');
            }, mb_strtolower($str, 'UTF-8'));
        }

        protected static function toIntOrNull(string $s) {
            $s = trim($s);
            if ($s === '') return null;
            if (!preg_match('/^-?\d+$/', $s)) return null;
            return (int)$s;
        }

        protected static function toFloat2OrNull(string $s) {
            $s = trim($s);
            if ($s === '' || !is_numeric($s)) return null;
            return number_format((float)$s, 2, '.', '');
        }

        protected static function expandYear2(int $yy): int {
            return ($yy >= 0 && $yy <= 69) ? (2000 + $yy) : (1900 + $yy);
        }

        protected static function isValidDate($y, $m, $d): bool {
            if ($y === null || $m === null || $d === null) return false;
            if ($y < 100) $y = self::expandYear2((int)$y);
            return checkdate((int)$m, (int)$d, (int)$y);
        }

        protected static function createDT(string $raw): ?DateTime {
            $candidates = [
                'Y-m-d','Y/m/d','Y.m.d','Y n j','Y-n-j',
                'm/d/Y','m-d-Y','m.d.Y','m/d/y','m-d-y','n/j/Y','n-j-Y',
                'd/m/Y','d-m-Y','d.m.Y','d/m/y','d-m-y','j/n/Y','j-n-Y',
            ];
            foreach ($candidates as $fmt) {
                $dt = DateTime::createFromFormat('!'.$fmt, $raw);
                if ($dt instanceof DateTime) return $dt;
            }
            $ts = strtotime($raw);
            return $ts ? (new DateTime())->setTimestamp($ts) : null;
        }
    }}
