<?php
namespace NumericDataTypes\DataType;

use DateTime;
use DateTimeZone;
use Laminas\View\Renderer\PhpRenderer;

abstract class AbstractDateTimeDataType extends AbstractDataType
{
    /**
     * Minimum and maximum years.
     *
     * When converted to Unix timestamps, anything outside this range would
     * exceed the minimum or maximum range for a 64-bit integer.
     */
    const YEAR_MIN = -292277022656;
    const YEAR_MAX = 292277026595;

    /**
     * ISO 8601 datetime pattern
     *
     * The standard permits the expansion of the year representation beyond
     * 0000–9999, but only by prior agreement between the sender and the
     * receiver. Given that our year range is unusually large we shouldn't
     * require senders to zero-pad to 12 digits for every year. Users would have
     * to a) have prior knowledge of this unusual requirement, and b) convert
     * all existing ISO strings to accommodate it. This is needlessly
     * inconvenient and would be incompatible with most other systems. Instead,
     * we require the standard's zero-padding to 4 digits, but stray from the
     * standard by accepting non-zero padded integers beyond -9999 and 9999.
     *
     * Note that we only accept ISO 8601's extended format: the date segment
     * must include hyphens as separators, and the time and offset segments must
     * include colons as separators. This follows the standard's best practices,
     * which notes that "The basic format should be avoided in plain text."
     */
    const PATTERN_ISO8601 = '^(?<date>(?<year>-?\d{4,})(-(?<month>\d{2}))?(-(?<day>\d{2}))?)(?<time>(T(?<hour>\d{2}))?(:(?<minute>\d{2}))?(:(?<second>\d{2}))?)(?<offset>((?<offset_hour>[+-]\d{2})?(:(?<offset_minute>\d{2}))?)|Z?)$';

    /**
     * Map from \DateTime format to standard Unicode format (ICU).
     *
     * @see https://www.php.net/manual/en/datetime.format.php
     * @see https://unicode-org.github.io/icu/userguide/format_parse/datetime/#datetime-format-syntax
     */
    protected $dateTimeToUnicode = [
        // ISO 8601.
        'Y-m-d\TH:i:sP' => "G yyyy-LL-dd'T'HH:mm:ss xxx",
        'Y-m-d\TH:iP' => "G yyyy-LL-dd'T'HH:mm xxx",
        'Y-m-d\THP' => "G yyyy-LL-dd'T'HHss xxx",
        'Y-m-d\TH:i:s' => "G yyyy-LL-dd'T'HH:mm:ss",
        'Y-m-d\TH:i' => "G yyyy-LL-dd'T'HH:mm",
        'Y-m-d\TH' => "G yyyy-LL-dd'T'HH",
        'Y-m-d' => 'G yyyy-LL-dd',
        'Y-m' => 'G yyyy-LL',
        'Y' => 'G yyyy',
        // Rendering. Use day before months, because English is an exception
        // among all languages that use natural order, from day to year.
        'F j, Y H:i:s P' => 'd LLLL yyyy G, HH:mm:ss xxx',
        'F j, Y H:i P' => 'd LLLL yyyy G, HH:mm xxx',
        'F j, Y H P' => 'd LLLL yyyy G, HH xxx',
        'F j, Y H:i:s' => 'd LLLL yyyy G, HH:mm:ss',
        'F j, Y H:i' => 'd LLLL yyyy G, HH:mm',
        'F j, Y H' => 'd LLLL yyyy G, HH:mm',
        'F j, Y' => 'd LLLL yyyy G',
        'F Y' => 'LLLL yyyy G',
        'Y' => 'yyyy G',
    ];

    /**
     * @var array Cache of date/times
     */
    protected static $dateTimes = [];

    /**
     * Get the decomposed date/time and DateTime object from an ISO 8601 value.
     *
     * Use $defaultFirst to set the default of each datetime component to its
     * first (true) or last (false) possible integer, if the specific component
     * is not passed with the value.
     *
     * Also used to validate the datetime since validation is a side effect of
     * parsing the value into its component datetime pieces.
     *
     * @throws \InvalidArgumentException
     * @param string $value
     * @param bool $defaultFirst
     * @return array
     */
    public static function getDateTimeFromValue($value, $defaultFirst = true)
    {
        if (isset(self::$dateTimes[$value][$defaultFirst ? 'first' : 'last'])) {
            return self::$dateTimes[$value][$defaultFirst ? 'first' : 'last'];
        }

        // Match against ISO 8601, allowing for reduced accuracy.
        $isMatch = preg_match(sprintf('/%s/', self::PATTERN_ISO8601), $value, $matches);
        if (!$isMatch) {
            throw new \InvalidArgumentException(sprintf('Invalid ISO 8601 datetime: %s', $value));
        }
        $matches = array_filter($matches); // remove empty values
        // An hour requires a day.
        if (isset($matches['hour']) && !isset($matches['day'])) {
            throw new \InvalidArgumentException(sprintf('Invalid ISO 8601 datetime: %s', $value));
        }
        // An offset requires a time.
        if (isset($matches['offset']) && !isset($matches['time'])) {
            throw new \InvalidArgumentException(sprintf('Invalid ISO 8601 datetime: %s', $value));
        }

        // Set the datetime components included in the passed value.
        $dateTime = [
            'value' => $value,
            'date_value' => $matches['date'],
            'time_value' => $matches['time'] ?? null,
            'offset_value' => $matches['offset'] ?? null,
            'year' => (int) $matches['year'],
            'month' => isset($matches['month']) ? (int) $matches['month'] : null,
            'day' => isset($matches['day']) ? (int) $matches['day'] : null,
            'hour' => isset($matches['hour']) ? (int) $matches['hour'] : null,
            'minute' => isset($matches['minute']) ? (int) $matches['minute'] : null,
            'second' => isset($matches['second']) ? (int) $matches['second'] : null,
            'offset_hour' => isset($matches['offset_hour']) ? (int) $matches['offset_hour'] : null,
            'offset_minute' => isset($matches['offset_minute']) ? (int) $matches['offset_minute'] : null,
        ];

        // Set the normalized datetime components. Each component not included
        // in the passed value is given a default value.
        $dateTime['month_normalized'] = $dateTime['month'] ?? ($defaultFirst ? 1 : 12);
        // The last day takes special handling, as it depends on year/month.
        $dateTime['day_normalized'] = $dateTime['day']
            ?? ($defaultFirst ? 1 : self::getLastDay($dateTime['year'], $dateTime['month_normalized']));
        $dateTime['hour_normalized'] = $dateTime['hour'] ?? ($defaultFirst ? 0 : 23);
        $dateTime['minute_normalized'] = $dateTime['minute'] ?? ($defaultFirst ? 0 : 59);
        $dateTime['second_normalized'] = $dateTime['second'] ?? ($defaultFirst ? 0 : 59);
        $dateTime['offset_hour_normalized'] = $dateTime['offset_hour'] ?? 0;
        $dateTime['offset_minute_normalized'] = $dateTime['offset_minute'] ?? 0;
        // Set the UTC offset (+00:00) if no offset is provided.
        $dateTime['offset_normalized'] = isset($dateTime['offset_value'])
            ? ('Z' === $dateTime['offset_value'] ? '+00:00' : $dateTime['offset_value'])
            : '+00:00';

        // Validate ranges of the datetime component.
        if ((self::YEAR_MIN > $dateTime['year']) || (self::YEAR_MAX < $dateTime['year'])) {
            throw new \InvalidArgumentException(sprintf('Invalid year: %s', $dateTime['year']));
        }
        if ((1 > $dateTime['month_normalized']) || (12 < $dateTime['month_normalized'])) {
            throw new \InvalidArgumentException(sprintf('Invalid month: %s', $dateTime['month_normalized']));
        }
        if ((1 > $dateTime['day_normalized']) || (31 < $dateTime['day_normalized'])) {
            throw new \InvalidArgumentException(sprintf('Invalid day: %s', $dateTime['day_normalized']));
        }
        if ((0 > $dateTime['hour_normalized']) || (23 < $dateTime['hour_normalized'])) {
            throw new \InvalidArgumentException(sprintf('Invalid hour: %s', $dateTime['hour_normalized']));
        }
        if ((0 > $dateTime['minute_normalized']) || (59 < $dateTime['minute_normalized'])) {
            throw new \InvalidArgumentException(sprintf('Invalid minute: %s', $dateTime['minute_normalized']));
        }
        if ((0 > $dateTime['second_normalized']) || (59 < $dateTime['second_normalized'])) {
            throw new \InvalidArgumentException(sprintf('Invalid second: %s', $dateTime['second_normalized']));
        }
        if ((-23 > $dateTime['offset_hour_normalized']) || (23 < $dateTime['offset_hour_normalized'])) {
            throw new \InvalidArgumentException(sprintf('Invalid hour offset: %s', $dateTime['offset_hour_normalized']));
        }
        if ((0 > $dateTime['offset_minute_normalized']) || (59 < $dateTime['offset_minute_normalized'])) {
            throw new \InvalidArgumentException(sprintf('Invalid minute offset: %s', $dateTime['offset_minute_normalized']));
        }

        // Set the ISO 8601 format.
        if (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute']) && isset($dateTime['second']) && isset($dateTime['offset_value'])) {
            $format = 'Y-m-d\TH:i:sP';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute']) && isset($dateTime['offset_value'])) {
            $format = 'Y-m-d\TH:iP';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['offset_value'])) {
            $format = 'Y-m-d\THP';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute']) && isset($dateTime['second'])) {
            $format = 'Y-m-d\TH:i:s';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute'])) {
            $format = 'Y-m-d\TH:i';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour'])) {
            $format = 'Y-m-d\TH';
        } elseif (isset($dateTime['month']) && isset($dateTime['day'])) {
            $format = 'Y-m-d';
        } elseif (isset($dateTime['month'])) {
            $format = 'Y-m';
        } else {
            $format = 'Y';
        }
        $dateTime['format_iso8601'] = $format;

        // Set the render format.
        if (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute']) && isset($dateTime['second']) && isset($dateTime['offset_value'])) {
            $format = 'F j, Y H:i:s P';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute']) && isset($dateTime['offset_value'])) {
            $format = 'F j, Y H:i P';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['offset_value'])) {
            $format = 'F j, Y H P';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute']) && isset($dateTime['second'])) {
            $format = 'F j, Y H:i:s';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour']) && isset($dateTime['minute'])) {
            $format = 'F j, Y H:i';
        } elseif (isset($dateTime['month']) && isset($dateTime['day']) && isset($dateTime['hour'])) {
            $format = 'F j, Y H';
        } elseif (isset($dateTime['month']) && isset($dateTime['day'])) {
            $format = 'F j, Y';
        } elseif (isset($dateTime['month'])) {
            $format = 'F Y';
        } else {
            $format = 'Y';
        }
        $dateTime['format_render'] = $format;

        // Adding the DateTime object here to reduce code duplication. To ensure
        // consistency, use Coordinated Universal Time (UTC) if no offset is
        // provided. This avoids automatic adjustments based on the server's
        // default timezone.
        // With strict type, "now" is required.
        $dateTime['date'] = new DateTime('now', new DateTimeZone($dateTime['offset_normalized']));
        $dateTime['date']->setDate(
            $dateTime['year'],
            $dateTime['month_normalized'],
            $dateTime['day_normalized']
        )->setTime(
            $dateTime['hour_normalized'],
            $dateTime['minute_normalized'],
            $dateTime['second_normalized']
        );
        self::$dateTimes[$value][$defaultFirst ? 'first' : 'last'] = $dateTime; // Cache the date/time
        return $dateTime;
    }

    /**
     * Get the last day of a given year/month.
     *
     * @param int $year
     * @param int $month
     * @return int
     */
    public static function getLastDay($year, $month)
    {
        switch ($month) {
            case 2:
                // February (accounting for leap year)
                $leapYear = date('L', mktime(0, 0, 0, 1, 1, $year));
                return $leapYear ? 29 : 28;
            case 4:
            case 6:
            case 9:
            case 11:
                // April, June, September, November
                return 30;
            default:
                // January, March, May, July, August, October, December
                return 31;
        }
    }

    protected function selectedLang(PhpRenderer $view, array $options = []): string
    {
        if (isset($options['lang'])) {
            $lang = is_array($options['lang']) ? reset($options['lang']) : $options['lang'];
        }
        return empty($lang) ? (string) $view->lang() : (string) $lang;
    }

    protected function renderIntlDate(array $date, array $options, PhpRenderer $view): string
    {
        // Lang is the default option for compatibility with some datatypes.
        $lang = $this->selectedLang($view, is_array($options) ? $options : ['lang' => $options]);
        if (!$lang || substr($lang, 0, 2) === 'en') {
            return $date['date']->format($date['format_render']);
        }

        $intlDateFormatter = new \IntlDateFormatter($lang, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE);

        // Manage Gregorian negative dates: because year 0 does not exist, the
        // representation of negative dates is complex.
        // For example, 15 March -44 is converted into 17 March -45.
        // For now, keep the standard render for them, but not well translated.
        // Setlocale() is not used with DateTime, that is always in English.
        if ($date['date']->format('Y') <= 0) {
            return $date['date']->format($date['format_render']);
            // A clone is required to avoid to modify stored date.
            // $dateNeg = clone $date['date'];
            // To add one year does not work, because there will be a growing
            // offset due to the leap year.
            // // $dateNeg->add(new \DateInterval('P1Y'));
            // $intlDateFormatter->setPattern($this->dateTimeToUnicode[$date['format_render']]);
            // return $intlDateFormatter->format($dateNeg);
        }

        // Most of the time, the era is useless.
        $intlDateFormatter->setPattern(str_replace(' G', '', $this->dateTimeToUnicode[$date['format_render']]));
        return $intlDateFormatter->format($date['date']);
    }
}
