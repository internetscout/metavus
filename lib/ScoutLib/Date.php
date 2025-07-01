<?PHP
#
#   FILE:  Date.php
#
#   Part of the ScoutLib application support library
#   Copyright 1999-2025 Edward Almasy and Internet Scout Research Group
#   http://scout.wisc.edu
#

namespace ScoutLib;
use Exception;
use InvalidArgumentException;

/**
 * A class for parsing and manipulating possibly-inexact dates and date ranges.
 */
class Date
{
    # ---- PUBLIC INTERFACE --------------------------------------------------

    const PRE_BEGINYEAR = 1;
    const PRE_BEGINMONTH = 2;
    const PRE_BEGINDAY = 4;
    const PRE_BEGINDECADE = 8;
    const PRE_BEGINCENTURY = 16;
    const PRE_ENDYEAR = 32;
    const PRE_ENDMONTH = 64;
    const PRE_ENDDAY = 128;
    const PRE_ENDDECADE = 256;
    const PRE_ENDCENTURY = 512;
    const PRE_INFERRED = 1024;
    const PRE_COPYRIGHT = 2048;
    const PRE_CONTINUOUS = 4096;

    /**
     * Object constructor.
     * @param string $BeginDate Date (or beginning date, if range).
     * @param string $EndDate Ending date (OPTIONAL, default to NULL).
     * @param int $Precision Known precision of date (ORed combination of
     *       self::PRE_ constants).  (OPTIONAL, defaults to NULL)
     * @param int $DebugLevel Debugging output level.
     */
    public function __construct(
        string $BeginDate,
        ?string $EndDate = null,
        ?int $Precision = null,
        int $DebugLevel = 0
    ) {
        # set debug state
        $this->DebugLevel = $DebugLevel;

        if ($this->DebugLevel) {
            print("Date:  Date(BeginDate=\"".$BeginDate
                    ."\" EndDate=\"".$EndDate."\" Precision="
                    .$this->formattedPrecision($Precision).")<br>\n");
        }

        $MonthNames = [
            "january" => 1,
            "february" => 2,
            "march" => 3,
            "april" => 4,
            "may" => 5,
            "june" => 6,
            "july" => 7,
            "august" => 8,
            "september" => 9,
            "october" => 10,
            "november" => 11,
            "december" => 12,
            "jan" => 1,
            "feb" => 2,
            "mar" => 3,
            "apr" => 4,
            "jun" => 6,
            "jul" => 7,
            "aug" => 8,
            "sep" => 9,
            "oct" => 10,
            "nov" => 11,
            "dec" => 12
        ];

        # Formats we need to parse:
        #   1999-9-19
        #   1999-9
        #   9-19-1999
        #   19-9-1999
        #   Sep-1999
        #   Sep 1999
        #   Sep 9 1999
        #   September 9, 1999
        #   September 9th, 1999
        #   1996,1999
        #   c1999
        #   1996-1999
        #   9/19/01
        #   9-19-01
        #   199909
        #   19990909
        #   09-Sep-1999
        #   09 Sep 1999

        # append end date to begin date if available
        $Date = $BeginDate;
        if ($EndDate !== null) {
            $Date .= " - ".$EndDate;
        }

        # strip off any leading or trailing whitespace
        $Date = trim($Date);

        # bail out if we don't have anything to parse
        if (strlen($Date) < 1) {
            return;
        }

        # check for and strip out inferred indicators ("[" and "]")
        $Prec = 0;
        if (preg_match("/\\[/", $Date)) {
            $Prec |= self::PRE_INFERRED;
            $Date = preg_replace("/[\\[\\]]/", "", $Date);
        }

        # check for and strip off copyright indicator (leading "c")
        if (preg_match("/^c/", $Date)) {
            $Prec |= self::PRE_COPYRIGHT;
            $Date = preg_replace("/^c/", "", $Date);
        }

        # check for and strip off continuous indicator (trailing "-")
        if (preg_match("/\\-$/", $Date)) {
            $Prec |= self::PRE_CONTINUOUS;
            $Date = preg_replace("/\\-$/", "", $Date);
        }

        # strip out any times
        $Date = preg_replace("/[0-9]{1,2}:[0-9]{2,2}[:]?[0-9]{0,2}/", "", $Date);
        $Date = trim($Date);

        $Date = strtolower($Date);

        # a regex to match short and long month names
        $MonthRegex = "(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may".
                "|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?".
                "|nov(?:ember)?|dec(?:ember)?)";

        # Here we'll construct a template regex for dates
        # We want a single regex that covers all the different formats
        # of date we understand, with the various components of the
        # date pulled out using named subexpressions (eg: (?P<name>)).
        # Annoyingly, we can't re-use the same name for subexpressions
        # that will never both be matched at once.
        # So, we have to number them (year1, year2, etc) and figure
        # out which one did match.
        # Use XX_ThingNumber in the parameterized subexpressions
        # (eg XX_year1).
        # We'll use string substitutions later to convert the XX_ to
        # begin_ and end_

        $DateRegex = "(".
                # Matched formats are separated by |, as this isn't used in any of the formats
                # First alternative will match the following formats:
                # 1999-09-19 | 19990909 | 1999-09 | 199909 | 1999
                "(?:(?P<XX_year1>\d{4})(?:-?(?P<XX_month1>\d{1,2})"
                ."(?:-?(?P<XX_day1>\d{1,2}))?)?)".
                # Second alternative will match the following formats:
                # 09-19-1999 | 19-09-1999 | 09/19/01 | 09-19-01
                "|(?:(?P<XX_month2>\d{1,2})[\/-](?P<XX_day2>\d{1,2})"
                ."[\/-](?P<XX_year2>(?:\d{2,4})))".
                # Third alternative will match the following formats:
                # 09-Sep-1999 | 09 Sep 1999 | Sep-1999 | Sep 1999
                "|(?:(?:(?P<XX_day3>\d+)[ -])?(?P<XX_month3>".$MonthRegex
                .")[ -](?P<XX_year3>\d{4}))".
                # Fourth alternative will match the following formats:
                # Sep 9 1999 | September 9th, 1999
                "|(?:(?P<XX_month4>".$MonthRegex
                .") (?P<XX_day4>\d{1,2})(?:(?:st|nd|rd|th|),)? (?P<XX_year4>\d{4}))".
                ")";

        # IMPORTANT: if more formats are added, bump this
        $NumberOfDateRegexes = 4;

        # construct the begin and end regexes for the date range
        $BeginRegex = str_replace('XX', 'Begin', $DateRegex);
        $EndRegex = str_replace('XX', 'End', $DateRegex);

        # glue them together, making the second one optional,
        # and do the matching
        if (preg_match(
            "/".$BeginRegex."(?:(?:(?: - )|,)".$EndRegex.")?/",
            $Date,
            $Matches
        )) {
            # pull out the Begin and End data from the matches array:
            # (set them first to 1 so that phpstan understands that they are being set)
            $BeginDay = 1;
            $BeginMonth = 1;
            $BeginYear = 1;
            $EndDay = 1;
            $EndMonth = 1;
            $EndYear = 1;
            foreach (array("Begin", "End") as $Time) {
                # extract the matching elements from the regex parse
                ${$Time."Day"} = $this->extractMatchData(
                    $Matches,
                    $Time."_day",
                    $NumberOfDateRegexes
                );
                ${$Time."Month"} = $this->extractMatchData(
                    $Matches,
                    $Time."_month",
                    $NumberOfDateRegexes
                );
                ${$Time."Year"} = $this->extractMatchData(
                    $Matches,
                    $Time."_year",
                    $NumberOfDateRegexes
                );

                # convert named months to month numbers:
                if (!is_null(${$Time."Month"}) && !is_numeric(${$Time."Month"})) {
                    ${$Time."Month"} = $MonthNames[${$Time."Month"}];
                }

                # handle 2-digit years
                if (!is_null(${$Time.'Year'}) &&
                        ${$Time."Year"} != 0 && ${$Time."Year"} < 100) {
                    ${$Time."Year"} += (${$Time."Year"} > 50) ? 1900 : 2000;
                }

                # deal with D-M-Y format, when we can detect it
                if (!is_null(${$Time."Month"}) && ${$Time."Month"} > 12) {
                    $Tmp = ${$Time."Month"};

                    ${$Time."Month"} = ${$Time."Day"};
                    ${$Time."Day"} = $Tmp;
                }
            }
        }

        # use current month if begin day but no begin month specified
        if (isset($BeginDay) && !isset($BeginMonth)) {
            $BeginMonth = intval(date("m"));
        }

        # use current year if begin month but no begin year specified
        if (isset($BeginMonth) && !isset($BeginYear)) {
            $BeginYear = intval(date("Y"));
        }

        # use begin year if end month but no end year specified
        if (isset($EndMonth) && isset($BeginYear) && !isset($EndYear)) {
            $EndYear = $BeginYear;
        }

        # validate that the day value is valid for the specified month
        if (isset($BeginDay) && isset($BeginMonth) && isset($BeginYear)) {
            if (!$this->isValidDayMonthCombo($BeginMonth, $BeginDay, $BeginYear)) {
                throw new InvalidArgumentException(
                    "Unable to parse date."
                );
            }
        }

        if (isset($EndDay) && isset($EndMonth) && isset($EndYear)) {
            if (!$this->isValidDayMonthCombo($EndMonth, $EndDay, $EndYear)) {
                throw new InvalidArgumentException(
                    "Unable to parse date."
                );
            }
        }

        # after we've shuffled around the numbers, check the result to see if
        # it looks valid, dropping that which doesn't
        foreach (array("Begin", "End") as $Time) {
            if (isset(${$Time."Year"}) && !(${$Time."Year"} >= 0)) {
                unset(${$Time."Year"});
            }

            if (isset(${$Time."Month"}) &&
                    !(${$Time."Month"} >= 1 && ${$Time."Month"} <= 12)) {
                unset(${$Time."Month"});
            }

            if (isset(${$Time."Day"}) &&
                    !(${$Time."Day"} >= 1 && ${$Time."Day"} <= 31)) {
                unset(${$Time."Day"});
            }
        }

        # if no begin date found and begin date value is not illegal
        if (!isset($BeginYear)
                && ($BeginDate != "0000-00-00")
                && ($BeginDate != "0000-00-00 00:00:00")) {
            # try system call to parse incoming date
            $UDateStamp = strtotime($BeginDate);
            if ($this->DebugLevel > 1) {
                print("Date:  calling strtotime"
                        ." to parse BeginDate \"".$BeginDate
                        ."\" -- strtotime returned \"".$UDateStamp."\"<br>\n");
            }

            # if system call was able to parse date
            if (($UDateStamp != -1) && ($UDateStamp !== false)) {
                # set begin date to value returned by system call
                $BeginYear = date("Y", $UDateStamp);
                $BeginMonth = date("n", $UDateStamp);
                $BeginDay = date("j", $UDateStamp);
            }
        }

        # if end date value supplied and no end date found and end date value
        #       is not illegal
        if (($EndDate != null) && !isset($EndYear)
                && ($EndDate != "0000-00-00")
                && ($EndDate != "0000-00-00 00:00:00")) {
            # try system call to parse incoming date
            $UDateStamp = strtotime($EndDate);

            # if system call was able to parse date
            if (($UDateStamp != -1) && ($UDateStamp !== false)) {
                # set begin date to value returned by system call
                $EndYear = date("Y", $UDateStamp);
                $EndMonth = date("n", $UDateStamp);
                $EndDay = date("j", $UDateStamp);
            }
        }

        # if end date is before begin date
        if ((isset($EndYear)
        && isset($BeginYear)
        && ($EndYear < $BeginYear))
                || (isset($BeginYear)
                && isset($EndYear)
                && ($EndYear == $BeginYear)
                        && isset($BeginMonth)
                        && isset($EndMonth)
                        && ($EndMonth < $BeginMonth))
                || (isset($BeginYear)
                && isset($EndYear)
                && ($EndYear == $BeginYear)
                        && isset($BeginMonth)
                        && isset($EndMonth)
                        && ($EndMonth == $BeginMonth)
                        && isset($BeginDay)
                        && isset($EndDay)
                        && ($EndDay < $BeginDay))) {
            # swap begin and end dates
            $TempYear = $BeginYear;
            $BeginYear = $EndYear;
            $EndYear = $TempYear;

            if (isset($BeginMonth) && isset($EndMonth)) {
                $TempMonth = $BeginMonth;
                $BeginMonth = $EndMonth;
                $EndMonth = $TempMonth;
            }

            if (isset($BeginDay) && isset($EndDay)) {
                $TempDay = $BeginDay;
                $BeginDay = $EndDay;
                $EndDay = $TempDay;
            }
        }

        # if precision value supplied by caller
        if ($Precision != null) {
            # use supplied precision value
            $this->Precision = $Precision;
        } else {
            # save new precision value
            if (isset($BeginYear)) {
                $Prec |= self::PRE_BEGINYEAR;
            }
            if (isset($BeginMonth)) {
                $Prec |= self::PRE_BEGINMONTH;
            }
            if (isset($BeginDay)) {
                $Prec |= self::PRE_BEGINDAY;
            }
            if (isset($EndYear)) {
                $Prec |= self::PRE_ENDYEAR;
            }
            if (isset($EndMonth)) {
                $Prec |= self::PRE_ENDMONTH;
            }
            if (isset($EndDay)) {
                $Prec |= self::PRE_ENDDAY;
            }
            $this->Precision = $Prec;
        }

        # save new date values
        if (($this->DebugLevel > 1) && isset($BeginYear)) {
            print("Date:  BeginYear = $BeginYear<br>\n");
        }
        if (($this->DebugLevel > 1) && isset($BeginMonth)) {
            print("Date:  BeginMonth = $BeginMonth<br>\n");
        }
        if (($this->DebugLevel > 1) && isset($BeginDay)) {
            print("Date:  BeginDay = $BeginDay<br>\n");
        }
        if (($this->DebugLevel > 1) && isset($EndYear)) {
            print("Date:  EndYear = $EndYear<br>\n");
        }
        if (($this->DebugLevel > 1) && isset($EndMonth)) {
            print("Date:  EndMonth = $EndMonth<br>\n");
        }
        if (($this->DebugLevel > 1) && isset($EndDay)) {
            print("Date:  EndDay = $EndDay<br>\n");
        }
        if ($this->DebugLevel > 1) {
            print("Date:  Precision =
                ".$this->formattedPrecision()."<br>\n");
        }

        if (!isset($BeginYear) && !isset($BeginMonth) && !isset($BeginDay) &&
            !isset($EndYear) && !isset($EndMonth) && !isset($EndDay)) {
            throw new InvalidArgumentException(
                "Unable to parse date."
            );
        }

        $this->BeginYear = isset($BeginYear) ? $BeginYear : null;
        $this->BeginMonth = isset($BeginMonth) ? $BeginMonth : null;
        $this->BeginDay = isset($BeginDay) ? $BeginDay : null;
        $this->EndYear = isset($EndYear) ? $EndYear : null;
        $this->EndMonth = isset($EndMonth) ? $EndMonth : null;
        $this->EndDay = isset($EndDay) ? $EndDay : null;
    }

    /**
     * Get date value suitable for display.
     * @return string Formatted date string.
     */
    public function formatted(): string
    {
        # if begin year available
        $DateString = "";
        if ($this->Precision & self::PRE_BEGINYEAR) {
            # start with begin year
            $DateString = sprintf("%04d", $this->BeginYear);

            # if begin month available
            if ($this->Precision & self::PRE_BEGINMONTH) {
                # add begin month
                $DateString .= "-".sprintf("%02d", $this->BeginMonth);

                # if begin day available
                if ($this->Precision & self::PRE_BEGINDAY) {
                    # add begin day
                    $DateString .= "-".sprintf("%02d", $this->BeginDay);
                }
            }

            # if end year available
            if ($this->Precision & self::PRE_ENDYEAR) {
                # separate dates with dash
                $DateString .= " - ";

                # add end year
                $DateString .= sprintf("%04d", $this->EndYear);

                # if end month available
                if ($this->Precision & self::PRE_ENDMONTH) {
                    # add end month
                    $DateString .= "-".sprintf("%02d", $this->EndMonth);

                    # if end day available
                    if ($this->Precision & self::PRE_ENDDAY) {
                        # add end day
                        $DateString .= "-".sprintf("%02d", $this->EndDay);
                    }
                }
            } else {
                # if date is open-ended
                if ($this->Precision & self::PRE_CONTINUOUS) {
                    # add dash to indicate open-ended
                    $DateString .= "-";
                }
            }

            # if copyright flag is set
            if ($this->Precision & self::PRE_COPYRIGHT) {
                # add on copyright indicator
                $DateString = "c".$DateString;
            }

            # if flag is set indicating date was inferred
            if ($this->Precision & self::PRE_INFERRED) {
                # add on inferred indicators
                $DateString = "[".$DateString."]";
            }
        }

        # return formatted date string to caller
        return $DateString;
    }

    /**
     * Get date in format specified like PHP date() format parameter.
     * @param string $Format Format string.
     * @param bool $ReturnEndDate If TRUE, return end date instead of begin.
     *       (OPTIONAL, defaults to FALSE)
     * @return string Formatted date string.
     */
    public function pFormatted(string $Format, bool $ReturnEndDate = false): string
    {
        if ($ReturnEndDate) {
            $Month = ($this->Precision & self::PRE_ENDMONTH) ? $this->EndMonth : 1;
            $Day = ($this->Precision & self::PRE_ENDDAY) ? $this->EndDay : 1;
            $Year = ($this->Precision & self::PRE_ENDYEAR) ? $this->EndYear : 1;
        } else {
            $Month = ($this->Precision & self::PRE_BEGINMONTH) ? $this->BeginMonth : 1;
            $Day = ($this->Precision & self::PRE_BEGINDAY) ? $this->BeginDay : 1;
            $Year = ($this->Precision & self::PRE_BEGINYEAR) ? $this->BeginYear : 1;
        }
        $Timestamp = mktime(0, 0, 0, $Month, $Day, $Year);
        if ($Timestamp === false) {
            throw new Exception("Invalid date for formatting (M:".$Month.", D:"
                    .$Day.", Y:".$Year.").");
        }
        return date($Format, $Timestamp);
    }

    /**
     * Get begin date (or end date if requested) formatted for SQL DATETIME field.
     * @param bool $ReturnEndDate If TRUE, return end date instead of begin.
     *       (OPTIONAL, defaults to FALSE)
     * @return string Formatted date string.
     */
    public function formattedForSql(bool $ReturnEndDate = false): string
    {
        return $this->pFormatted("Y-m-d H:i:s", $ReturnEndDate);
    }

    /**
     * Get begin time in ISO 8601 format.
     * @return string Formatted date string.
     */
    public function formattedISO8601(): string
    {
        # start out assuming date will be empty
        $DateString = "";

        # if begin year available
        if ($this->Precision & self::PRE_BEGINYEAR) {
            # start with begin year
            $DateString = sprintf("%04d", $this->BeginYear);

            # if begin month available
            if ($this->Precision & self::PRE_BEGINMONTH) {
                # add begin month
                $DateString .= sprintf("-%02d", $this->BeginMonth);

                # if begin day available
                if ($this->Precision & self::PRE_BEGINDAY) {
                    # add begin day
                    $DateString .= sprintf("-%02d", $this->BeginDay);
                }
            }
        }

        # return ISO 8601 formatted date string to caller
        return $DateString;
    }

    /**
     * Get normalized begin date, suitable for storing via SQL.
     * @return string|null Formatted date string, or NULL if no begin date is available.
     */
    public function beginDate()
    {
        # build date string based on current precision
        if ($this->Precision & self::PRE_BEGINYEAR) {
            if ($this->Precision & self::PRE_BEGINMONTH) {
                if ($this->Precision & self::PRE_BEGINDAY) {
                    $DateFormat = "%04d-%02d-%02d";
                } else {
                    $DateFormat = "%04d-%02d-01";
                }
            } else {
                $DateFormat = "%04d-01-01";
            }

            $DateString = sprintf(
                $DateFormat,
                $this->BeginYear,
                $this->BeginMonth,
                $this->BeginDay
            );
        } else {
            $DateString = null;
        }

        # return date string to caller
        return $DateString;
    }

    /**
     * Get normalized end date, suitable for storing via SQL.
     * @return string|null Formatted date string, or NULL if no end date is available.
     */
    public function endDate()
    {
        # build date string based on current precision
        if ($this->Precision & self::PRE_ENDYEAR) {
            if ($this->Precision & self::PRE_ENDMONTH) {
                if ($this->Precision & self::PRE_ENDDAY) {
                    $DateFormat = "%04d-%02d-%02d";
                } else {
                    $DateFormat = "%04d-%02d-01";
                }
            } else {
                $DateFormat = "%04d-01-01";
            }

            $DateString = sprintf(
                $DateFormat,
                $this->EndYear,
                $this->EndMonth,
                $this->EndDay
            );
        } else {
            $DateString = null;
        }

        # return date string to caller
        return $DateString;
    }

    /**
     * Get/set date precision (combination of self::PRE_ bit constants).
     * @param int $NewPrecision New precision value.  (OPTIONAL)
     * @return int|null Current precision value or NULL if unknown.
     */
    public function precision(?int $NewPrecision = null)
    {
        if ($NewPrecision != null) {
            $this->Precision = $NewPrecision;
        }
        return $this->Precision;
    }

    /**
     * Get SQL condition for records that match date.
     * @param string $FieldName Database column name that contains date (or
     *       begin date, if range).
     * @param string $EndFieldName Database column name that contains end
     *       date (for ranges).  (OPTIONAL, defaults to NULL)
     * @param string $Operator Comparison operator.  (OPTIONAL, defaults to "=")
     * @return string SQL condition.
     */
    public function sqlCondition(
        string $FieldName,
        ?string $EndFieldName = null,
        string $Operator = "="
    ): string {
        # if no date value is set
        if ($this->Precision < 1) {
            # if operator is equals
            if ($Operator == "=") {
                # construct conditional that will find null dates
                $Condition = "(".$FieldName." IS NULL OR ".$FieldName
                        ." < '0000-01-01 00:00:01')";
            } else {
                # construct conditional that will find non-null dates
                $Condition = "(".$FieldName." > '0000-01-01 00:00:00')";
            }
        } else {
            # use begin field name as end if no end field specified
            if ($EndFieldName == null) {
                $EndFieldName = $FieldName;
            }

            # determine begin and end of range
            $BeginYear = $this->BeginYear;
            if ($this->Precision & self::PRE_BEGINMONTH) {
                $BeginMonth = $this->BeginMonth;
                if ($this->Precision & self::PRE_BEGINDAY) {
                    $BeginDay = $this->BeginDay - 1;
                } else {
                    $BeginDay = 0;
                }
            } else {
                $BeginMonth = 1;
                $BeginDay = 0;
            }
            if ($this->Precision & self::PRE_ENDYEAR) {
                $EndYear = $this->EndYear;
                if ($this->Precision & self::PRE_ENDMONTH) {
                    $EndMonth = $this->EndMonth;
                    if ($this->Precision & self::PRE_ENDDAY) {
                        $EndDay = $this->EndDay;
                    } else {
                        $EndMonth++;
                        $EndDay = 0;
                    }
                } else {
                    $EndYear++;
                    $EndMonth = 1;
                    $EndDay = 0;
                }
            } else {
                $EndYear = $BeginYear;
                if ($this->Precision & self::PRE_BEGINMONTH) {
                    $EndMonth = $BeginMonth;
                    if ($this->Precision & self::PRE_BEGINDAY) {
                        $EndDay = $BeginDay + 1;
                    } else {
                        $EndMonth++;
                        $EndDay = 0;
                    }
                } else {
                    $EndYear++;
                    $EndMonth = 1;
                    $EndDay = 0;
                }
            }
            $RangeBeginTimestamp = mktime(23, 59, 59, $BeginMonth, $BeginDay, $BeginYear);
            if ($RangeBeginTimestamp === false) {
                throw new Exception("Invalid range begin (M:".$BeginMonth.", D:"
                        .$BeginDay.", Y:".$BeginYear.").");
            }
            $RangeBegin = "'".date("Y-m-d H:i:s", $RangeBeginTimestamp)."'";
            $RangeEndTimestamp = mktime(23, 59, 59, $EndMonth, $EndDay, $EndYear);
            if ($RangeEndTimestamp === false) {
                throw new Exception("Invalid range end (M:".$EndMonth.", D:"
                        .$EndDay.", Y:".$EndYear.").");
            }
            $RangeEnd = "'".date("Y-m-d H:i:s", $RangeEndTimestamp)."'";

            # construct SQL condition
            switch ($Operator) {
                case ">":
                    $Condition = " ".$FieldName." > ".$RangeEnd." ";
                    break;

                case ">=":
                    $Condition = " ".$FieldName." > ".$RangeBegin." ";
                    break;

                case "<":
                    $Condition = " ".$FieldName." <= ".$RangeBegin." ";
                    break;

                case "<=":
                    $Condition = " ".$FieldName." <= ".$RangeEnd." ";
                    break;

                case "!=":
                    $Condition = " (".$FieldName." <= ".$RangeBegin.""
                            ." OR ".$FieldName." > ".$RangeEnd.") ";
                    break;

                case "=":
                default:
                    $Condition = " (".$FieldName." > ".$RangeBegin.""
                            ." AND ".$FieldName." <= ".$RangeEnd.") ";
                    break;
            }
        }

        # return condition to caller
        return $Condition;
    }

    /**
     * Get string containing printable version of precision flags.
     * @param int $Precision Precision to use.  (OPTIONAL, defaults to
     *       current precision value for date)
     * @return string Printable precision string.
     */
    public function formattedPrecision(?int $Precision = null): string
    {
        if ($Precision === null) {
            $Precision = $this->Precision;
        }
        $String = "";
        if ($Precision & self::PRE_BEGINYEAR) {
            $String .= "| BEGINYEAR ";
        }
        if ($Precision & self::PRE_BEGINMONTH) {
            $String .= "| BEGINMONTH ";
        }
        if ($Precision & self::PRE_BEGINDAY) {
            $String .= "| BEGINDAY ";
        }
        if ($Precision & self::PRE_BEGINDECADE) {
            $String .= "| BEGINDECADE ";
        }
        if ($Precision & self::PRE_ENDYEAR) {
            $String .= "| ENDYEAR ";
        }
        if ($Precision & self::PRE_ENDMONTH) {
            $String .= "| ENDMONTH ";
        }
        if ($Precision & self::PRE_ENDDAY) {
            $String .= "| ENDDAY ";
        }
        if ($Precision & self::PRE_ENDDECADE) {
            $String .= "| ENDDECADE ";
        }
        if ($Precision & self::PRE_INFERRED) {
            $String .= "| INFERRED ";
        }
        if ($Precision & self::PRE_COPYRIGHT) {
            $String .= "| COPYRIGHT ";
        }
        if ($Precision & self::PRE_CONTINUOUS) {
            $String .= "| CONTINUOUS ";
        }
        $String = preg_replace("/^\\|/", "", $String);
        return $String;
    }

    /**
     * Determine if a date is valid
     * @param string $BeginDate Date (or beginning date, if range).
     * @param string $EndDate Ending date (OPTIONAL, default to NULL).
     * @return bool TRUE for valid dates, FALSE otherwise.
     */
    public static function isValidDate(
        string $BeginDate,
        ?string $EndDate = null
    ): bool {
        $Result = true;
        # TODO: This is a temporary solution because Date::__construct()
        # erroneously doesn't throw an exception when passed an empty begin
        # date. This should be removed when Date::__construct() is updated to
        # not accept empty dates.
        if (strlen(trim($BeginDate)) < 1) {
            return false;
        }

        try {
            new self($BeginDate, $EndDate);
        } catch (InvalidArgumentException $e) {
            $Result = false;
        }
        return $Result;
    }

    # ---- PRIVATE INTERFACE -------------------------------------------------

    private $BeginDay;
    private $BeginMonth;
    private $BeginYear;
    private $EndDay;
    private $EndMonth;
    private $EndYear;
    private $Precision;
    private $DebugLevel;

    /**
     * Return the first non-empty parameterized subexpression match.
     * @param array $Matches Match array from preg_match().
     * @param string $Member Array index prefix.
     * @param int $Max Maximum number of search elements.
     * @return string|null The first non-empty element, or if they are all empty,
     *       NULL is returned.
     */
    private function extractMatchData(array $Matches, string $Member, int $Max)
    {
        for ($Index = 1; $Index <= $Max; $Index++) {
            if (isset($Matches[$Member.$Index]) && strlen($Matches[$Member.$Index]) > 0) {
                $Data = $Matches[$Member.$Index];
                return is_numeric($Data) ? intval($Data) : $Data;
            }
        }
        return null;
    }

    /**
     * Given the year and month, determine if day is valid.
     * For example, 02/29/2000 is valid but 04/31/2000 is not
     * @param int $Month
     * @param int $Day
     * @param int $Year
     * @return bool TRUE for days that are in bounds, FALSE for days out of bounds
     */
    private function isValidDayMonthCombo($Month, $Day, $Year): bool
    {
        $LEAP_YEAR = 4;
        # If year is 0000, use 0004 instead because:
        # - checkdate accepts years between 1 and 32767 inclusive
        # - in some systems 0000 is a leap year, so we should accept
        #   0000-02-29. See https://en.wikipedia.org/wiki/Proleptic_Gregorian_calendar
        $YearToCheck = $Year === 0 ? $LEAP_YEAR : $Year;
        return checkdate($Month, $Day, $YearToCheck);
    }
}
