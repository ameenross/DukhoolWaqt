<?php
/**
 * @file
 * A class library to calculate Islamic prayer times, qibla and more.
 *
 * @TODO
 * - Further restructuring of the code.
 * - Documentation.
 * - Correct times for high latitude.
 * - Fix moon azimuth calculation.
 * - Calculate moon elevation.
 * - Functions to return the horizontal coordinates of the sun and the moon.
 * - Calculate moon illumination.
 * - Figure out whether the algorithms implemented use JD(TT), JD(UT1) or else.
 * - Figure out how to properly convert from:
 *   - Unix time ->
 *   - UTC ->
 *   - UT1 ->
 *   - TT
 * - Add tests.
 */

/**
 * Base class for DukhoolWaqt, containing constants and basic functions.
 */
class DukhoolWaqtBase {

  /**
   * Geographical latitude of the Ka'aba, in degrees.
   */
  const kaabaLat = 21.422517;

  /**
   * Geographical longitude of the Ka'aba, in degrees.
   */
  const kaabaLng = 39.826166;

  // @TODO: Remove default location functionality.
  // Default location coordinates & timezone (Masjid An-Nabawi)
  const defaultLat = 24.494647;
  const defaultLng = 39.770508;
  const defaultZone = 3; // GMT + 3

  /**
   * The sun's altitude at sunrise and sunset, in degrees.
   */
  const sunset = -0.8333;

  /**
   * Used to compute sidereal time.
   * @TODO: These constants are used only once, remove.
   * http://aa.usno.navy.mil/faq/docs/GAST.php
   */
  const earthSrt1 = 6.697374558;
  const earthSrt2 = 0.06570982441908;
  const earthSrt3 = 1.002737909350795;
  const earthSrt4 = 0.000026;

  /**
   * Used to compute obliquity of the ecliptic
   *
   * @TODO: Make a function for this, and update to more precise algorithm.
   * @see: https://en.wikipedia.org/wiki/Axial_tilt
   */
  const earthObl1 = 0.40909;
  const earthObl2 = -0.0002295;

  /**
   * Used to compute the sun's mean longitude, anomaly and equation of center.
   *
   * @TODO: Add a function to calculate Sun's mean longitude. Remove constants.
   */
  const sunLng1 = 4.89506;
  const sunLng2 = 628.33197;
  const sunAno1 = 6.24006;
  const sunAno2 = 628.30195;
  const sunCenter1 = 0.03342;
  const sunCenter2 = -0.0000873;
  const sunCenter3 = 0.000349;

  /**
   * Unix epoch in Julian Date.
   *
   * The unix epoch (1970/01/01 0:00 UTC) in Julian Date. The difference
   * between UTC and TT is assumed constant.
   *
   * @see: https://en.wikipedia.org/wiki/Unix_time
   * @see: https://en.wikipedia.org/wiki/Julian_day
   * @see: https://en.wikipedia.org/wiki/Coordinated_Universal_Time
   * @see: https://en.wikipedia.org/wiki/Terrestrial_Time
   */
  const unixEpochJD = 2440587.500761306;

  /**
   * J2000 epoch in Julian Date.
   *
   * The J2000 epoch (2000/01/01 12:00 TT) In Julian Date. The difference
   * between UTC and TT is assumed constant.
   *
   * @see: https://en.wikipedia.org/wiki/Unix_time
   * @see: https://en.wikipedia.org/wiki/Julian_day
   * @see: https://en.wikipedia.org/wiki/Coordinated_Universal_Time
   * @see: https://en.wikipedia.org/wiki/Terrestrial_Time
   */
  const J2000EpochJD = 2451545;

  /**
   * Length of a day (Unix time) in seconds.
   *
   * @see: https://en.wikipedia.org/wiki/Day
   * @see: https://en.wikipedia.org/wiki/Unix_time
   *
   * @TODO: Use this constant everywhere it occurs.
   */
  //const unixDay = 86400;

  /**
   * The default accuracy.
   *
   * @TODO: Remove accuracy logic.
   */
  const defaultAccuracy = 2;

  /**
   * Geographical longitude of the location to process.
   */
  protected $longitude = NULL;

  /**
   * Geographical latitude of the location to process.
   */
  protected $latitude = NULL;
  
  /**
   * The timezone (numerical GMT offset) of the location to process.
   */
  protected $timeZone = NULL;

  /**
   * An associative array describing the location to be processed.
   * - longitude: The location's geographical longitude.
   * - latitude: The location's geographical latitude.
   * - timezone: The location's named timezone.
   *
   * @see: http://php.net/manual/en/timezones.php
   *
   * @TODO: Change the API to use this and remove $latitude, $longitude,
   *   $timeZone.
   */
  //public $location = array('longitude' => NULL, 'latitude' => NULL, 'timezone' => '');

  /**
   * The unix time used for the calculations, when no argument is given.
   *
   * @TODO: Make this public and change the API to use this instead of using
   *   function arguments. Also let it default to time().
   */
  protected $time = NULL;

  /**
   * Settings defining the methods and parameters used to determine the prayer
   * times.
   *
   * @TODO: Store these settings differently.
   */
  protected $calcSettings = array(
    'angle' => array(
      'fajr' => NULL,
      'asr' => NULL, // A factor rather than an angle, here for convenience
      'isha' => NULL,
    ),
    'ishaMinutes' => NULL,
    'adjustMins' => array(
      'fajr' => NULL,
      'shurooq' => NULL,
      'dhohr' => NULL,
      'asr' => NULL,
      'maghrib' => NULL,
      'isha' => NULL,
    ),
  );

  /**
   * The ID of the calculation method to use for Fajr & Isha.
   *
   * @TODO: See above.
   */
  protected $methodID = NULL;

  /**
   * @TODO: See above.
   */
  protected $methodName = array('Karachi', 'ISNA', 'MWL', 'Makkah', 'Egypt');
  protected $asrMethodName = array('Shafii', 'Hanafi');

  /**
   * Converts a Unix timestamp to Julian Date.
   */
  protected function unix2JD($unix) {
    return self::unixEpochJD + $unix / 86400;
  }

  /**
   * Converts a Julian Date to a Unix timestamp.
   */
  protected function JD2unix($JD) {
    return 86400 * ($JD - self::unixEpochJD);
  }

  /**
   * Determines which day is most appropriate for prayer time calculation.
   *
   * This makes sure that prayer times between the last and next midnight will
   * be calculated, as opposed to simply the prayer times for the current date.
   * The latter can sometimes cause confusion.
   *
   * @param int $time
   *   The time to use to determine prayer times.
   *
   * @return
   *   The Unix timestamp for 0:00 of the day to calculate prayer times for.
   */
  protected function basetime($time) {
    $daybegin = floor($time / 86400) * 86400 - $this->timeZone * 3600;
    $midnight1 = $this->midnight($daybegin);
    $midnight2 = $this->midnight($daybegin + 86400);

    return $daybegin + 86400 * (($time > $midnight2) - ($time < $midnight1));
  }

  /**
   * Calculates the time of mean solar noon.
   *
   * @param int $basetime
   *   Unix timestamp of 0:00 of the day of which to determine the mean
   *   (fictitious) solar midnight.
   *
   * @return
   *   The Unix timestamp of mean solar noon.
   *
   * @see: https://en.wikipedia.org/wiki/Equation_of_time
   */
  protected function midday($basetime) {
    return $basetime + (180 + $this->timeZone * 15 - $this->longitude) * 240;
  }

  /**
   * Calculates the time of apparent solar midnight.
   *
   * @param int $basetime
   *   Unix timestamp of 0:00 of the day of which to determine the apparent
   *   (or true) solar midnight.
   *
   * @return
   *   The Unix timestamp of apparent solar midnight.
   *
   * @see: https://en.wikipedia.org/wiki/Equation_of_time
   */
  protected function midnight($basetime) {
    $midnight = $basetime + ($this->timeZone * 15 - $this->longitude) * 240;
    return $midnight - $this->eot($midnight);
  }

  /**
   * Cotangent
   *
   * Returns the cotangent of the $arg parameter.
   *
   * @param float $arg
   *   A value in radians.
   *
   * @return
   *   The cotangent of $arg.
   *
   * @see http://php.net/manual/en/function.tan.php
   */
  protected function cot($arg) {
    return tan(M_PI_2 - $arg);
  }

  /**
   * Arc cotangent of two variables
   *
   * This function calculates the arc cotangent of the two variables $x and $y.
   * It is similar to calculating the arc cotangent of $y / $x, except that the
   * signs of both arguments are used to determine the quadrant of the result.
   *
   * The function returns the result in radians, which is between -PI and PI
   * (inclusive).
   *
   * @param float $y
   *   Dividend parameter.
   * @param float $x
   *   Divisor parameter.
   *
   * @return
   *   The arc cotangent of $y/$x in radians.
   *
   * @see http://php.net/manual/en/function.atan2.php
   */
  protected function acot2($y, $x) {
    return atan2($x, $y);
  }
}

/**
 * Implementation of a prayer time calculation algorithm.
 */
class DukhoolWaqt extends DukhoolWaqtBase {

  /**
   * Creates a new DukhoolWaqt object.
   *
   * @TODO: Change API.
   */
  function __construct($lat = NULL, $lng = NULL, $zone = NULL, $method = NULL,
$asrMethod = NULL, $adjustMins = array(NULL, NULL, NULL, NULL, NULL, NULL)) {
    $this->setLocation($lat, $lng);
    $this->setTimeZone($zone);
    $this->setMethod($method);
    $this->setAsrMethod($asrMethod);
    $this->setAdjustMins($adjustMins);
  }

  /**
   * Sets the location.
   *
   * @TODO: Remove in favor of $this->location.
   */
  function setLocation($lat = NULL, $lng = NULL) {
    if (is_numeric($lat) && is_numeric($lng) &&
$lat < 90 && $lat > -90 && $lng < 180 && $lng >= -180) {
      $this->latitude = $lat;
      $this->longitude = $lng;
    }
    else {
      $this->latitude = self::defaultLat;
      $this->longitude = self::defaultLng;
    }
  }

  /**
   * Sets the timezone.
   *
   * @TODO: Remove in favor of $this->location.
   */
  function setTimeZone($zone = NULL) {
    if ($zone <= 15 && $zone >= -13) {
      $this->timeZone = $zone;
    }
    else {
      $this->timeZone = self::defaultZone;
    }
  }

  /**
   * Sets the calculation method for Fajr and Isha.
   *
   * @TODO: Remove in favor of $this->options. Need to think of a way to set
   *   calculation method by their name.
   */
  function setMethod($method = NULL) {

    if (is_null($method)) {
      $methodID = 0;
    }
    elseif (is_string($method)) {
      for ($i = 0; $i < 5; $i++) {
        if (strcasecmp($method, $this->methodName[$i]) == 0) {
          $methodID = $i;
          break;
        }
      }
      !isset($methodID) ? $methodID = 0 : NULL;
    }
    elseif (is_int($method) && $method >= 0 && $method < 5) {
      $methodID = $method;
    }
    else {
      $methodID = 0;
    }

    $this->methodID = $methodID;

    $settings =& $this->calcSettings; // Reference class calcSettings array
    switch ($methodID) {
      case 0: // Karachi
        $settings['angle']['fajr'] = -18;
        $settings['angle']['isha'] = -18;
        $settings['ishaMinutes'] = 0;
        break;
      case 1: // ISNA (Islamic Society of North America)
        $settings['angle']['fajr'] = -15;
        $settings['angle']['isha'] = -15;
        $settings['ishaMinutes'] = 0;
        break;
      case 2: // MWL (Muslim World League)
        $settings['angle']['fajr'] = -18;
        $settings['angle']['isha'] = -17;
        $settings['ishaMinutes'] = 0;
        break;
      case 3: // Makkah (Umm al Quraa)
        $settings['angle']['fajr'] = -19;
        $settings['angle']['isha'] = self::sunset;
        $settings['ishaMinutes'] = 90;
        break;
      case 4: // Egypt
        $settings['angle']['fajr'] = -19.5;
        $settings['angle']['isha'] = -17.5;
        $settings['ishaMinutes'] = 0;
        break;
    }
  }

  /**
   * Sets the calculation method for Asr.
   *
   * @TODO: Remove in favor of $this->options. Need to think of a way to set
   *   calculation method by their name.
   */
  function setAsrMethod($asrMethod = NULL) {
    if (is_null($asrMethod)) {
      $asrMethodID = 0;
    }
    elseif (is_string($asrMethod)) {
      for ($i = 0; $i < 2; $i++) {
        if (strcasecmp($asrMethod, $this->asrMethodName[$i]) == 0) {
          $asrMethodID = $i;
          break;
        }
      }
      !isset($asrMethodID) ? $asrMethodID = 0 : NULL;
    }
    elseif (is_int($asrMethod) && $asrMethod >= 0 && $asrMethod < 2) {
      $asrMethodID = $asrMethod;
    }
    else {
      $asrMethodID = 0;
    }

    $this->calcSettings['angle']['asr'] = $asrMethodID;
  }

  /**
   * Sets adjustments to prayer times.
   *
   * @TODO: Remove in favor of $this->options.
   */
  function setAdjustMins($adjustMins = array(NULL, NULL, NULL, NULL, NULL,
NULL)) {
    if (is_array($adjustMins)) {
      $setDefault = FALSE;
      for ($i = 0; $i < 6; $i++) {
        if (!array_key_exists($i, $adjustMins)) {
          $setDefault = TRUE;
          break;
        }
      }
      if (!$setDefault) {
        for ($i = 0; $i < 6; $i++) {
          if (!is_numeric($adjustMins[$i])) {
            $setDefault = TRUE;
            break;
          }
        }
      }
    }
    else {
      $setDefault = TRUE;
    }

    if ($setDefault) {
      $adjustMins = array(0, 0, 0, 0, 0, 0);
    }

    $settings =& $this->calcSettings['adjustMins']; // Makes below line cleaner
    $settings = array_combine(array_keys($settings), $adjustMins);
  }

  /**
   * Get function.
   *
   * @TODO: Remove.
   */
  function getLocation() {
    return array($this->latitude, $this->longitude);
  }

  /**
   * Get function.
   *
   * @TODO: Remove.
   */
  function getLatitude() {
    return $this->latitude;
  }

  /**
   * Get function.
   *
   * @TODO: Remove.
   */
  function getLongitude() {
    return $this->longitude;
  }

  /**
   * Get function.
   *
   * @TODO: Remove.
   */
  function getTimeZone() {
    return $this->timeZone;
  }

  /**
   * Get function.
   *
   * @TODO: Remove.
   */
  function getMethod() {
    return $this->methodName[$this->methodID];
  }

  /**
   * Get function.
   *
   * @TODO: Remove.
   */
  function getMethodID() {
    return $this->methodID;
  }

  /**
   * Get function.
   *
   * @TODO: Remove.
   */
  function getAsrMethod() {
    return $this->asrMethodName[$this->calcSettings['angle']['asr']];
  }

  /**
   * Get function.
   *
   * @TODO: Remove.
   */
  function getAsrMethodID() {
    return $this->calcSettings['angle']['asr'];
  }

  /**
   * Get function.
   *
   * @TODO: Remove.
   */
  function getAdjustMins() {
    return array_values($this->calcSettings['adjustMins']);
  }

  /**
   * Calculates the Qibla direction.
   *
   * @return
   *   Returns the cardinal direction to the Ka'aba (Qibla) in degrees.
   *
   * @see: https://en.wikipedia.org/wiki/Qibla
   * @see: https://en.wikipedia.org/wiki/Cardinal_direction
   */
  function getQibla() {
    $A = deg2rad(self::kaabaLng - $this->longitude);
    $b = deg2rad(90 - $this->latitude);
    $c = deg2rad(90 - self::kaabaLat);

    $C = rad2deg(atan2(sin($A), sin($b) * $this->cot($c) - cos($b) * cos($A)));

    // Azimuth is not negative
    $C += ($C < 0) * 360;

    return $C;
  }

  /**
   * Calculates the sun's direction.
   *
   * @return
   *   Returns the azimuth of the sun in degrees.
   *
   * @see: https://en.wikipedia.org/wiki/Azimuth
   */
  function getSunAzimuth($time = NULL) {
    !is_int($time) ? $time = time() : NULL;
    $T = ($this->unix2JD($time) - self::J2000EpochJD) / 36525;

    // Solar coordinates
    $K = self::earthObl1 + self::earthObl2 * $T;
    $Rs = $this->rightAscension($T);

    // Horizontal coordinates
    $H = deg2rad($this->meanSiderealTime($time) * 15) - $Rs;
    $Ds = $this->declination($time);
    $B = deg2rad($this->latitude);

    // Azimuth
    $A = rad2deg(atan2(-sin($H), tan($Ds) * cos($B) - sin($B) * cos($H)));

    // Azimuth is not negative
    $A += ($A < 0) * 360;

    return $A;
  }

  /**
   * Calculates the moon's direction.
   *
   * @return
   *   Returns the azimuth of the moon in degrees.
   *
   * @see: https://en.wikipedia.org/wiki/Azimuth
   */
  function getMoonAzimuth($time = NULL) {
    !is_int($time) ? $time = time() : NULL;
    $accuracy = self::defaultAccuracy;

    $T = ($this->unix2JD($time) - self::J2000EpochJD) / 36525;
    $d = $this->unix2JD($time) - self::J2000EpochJD;
    $T = ($d + 1.5) / 36525; //We must do this due to an error in the algorithm

    if (!is_int($accuracy) || $accuracy < 0 || $accuracy > 3) {
      $accuracy = self::defaultAccuracy;
    }

    // The moon's orbital elements (these are actually 1.5 days off!!)
    $N = 2.183805 - 33.7570736 * $T;
    $i = 0.089804;
    $w = 5.551254 + 104.7747539 * $T;
    $a = 60.2666;
    $e = 0.054900;
    $M = 2.013506 + 8328.69142630 * $T;

    $E = $M + $e * sin($M) * (1 + $e * cos($M));

    if ($accuracy > 0) {
      $delta = 1;
      for ($z = 0; $z < 9 && (abs($delta) > 0.000001); $z++) {
        $delta = $E - $e * sin($E) - $M;
        $delta /= 1 - $e * cos($E);
        $E -= $delta;
      }
    }

    $xv = $a * (cos($E) - $e);
    $yv = $a * sqrt(1 - $e * $e) * sin($E);
    $v = atan2($yv, $xv);
    $r = sqrt($xv * $xv + $yv * $yv);

    $xg = $r * (cos($N) * cos($v + $w) - sin($N) * sin($v + $w) * cos($i));
    $yg = $r * (sin($N) * cos($v + $w) + cos($N) * sin($v + $w) * cos($i));
    $zg = $r * sin($v + $w) * sin($i);

    // If high accuracy is required, compute perbutations
    if ($accuracy > 1) {
      $mLat = atan2($zg, sqrt($xg * $xg + $yg * $yg));
      $mLon = atan2($yg, $xg);

      // Again, these are probably 1.5 days off!!
      $Ms = 4.468863 + 628.3019404 * $T;
      //$Ls = 4.938242 + .0300212 * $T + $Ms;

      $Ls = $this->sunTrueLongitude($T);

      $Lm = $N + $w + $M;
      $D = $Lm - $Ls;
      $F = $Lm - $N;

      $mLat -= .003019 * sin($F - 2 * $D);
      if ($accuracy > 2) {
        $mLat -= .00096 * sin($M - $F - 2 * $D);
        $mLat -= .00080 * sin($M + $F - 2 * $D);
        $mLat += .00058 * sin($F + 2 * $D);
        $mLat += .00030 * sin(2 * $M + $F);
      }

      $mLon -= .02224 * sin($M - 2 * $D);
      $mLon += .0115 * sin(2 * $D);
      $mLon -= .00325 * sin($Ms);
      if ($accuracy > 2) {
        $mLon -= .0010 * sin(2 * $M - 2 * $D);
        $mLon -= .00099 * sin($M - 2 * $D + $Ms);
        $mLon += .00093 * sin($M + 2 * $D);
        $mLon += .00080 * sin(2 * $D - $Ms);
        $mLon += .00072 * sin($M - $Ms);
        $mLon -= .00061 * sin($D);
        $mLon -= .00054 * sin($M + $Ms);
        $mLon -= .00026 * sin(2 * $F - 2 * $D);
        $mLon += .00019 * sin($M - 4 * $D);
      }

      $r -= 0.58 * cos($M - 2 * $D) + 0.46 * cos(2 * $D);

      $xg = $r * cos($mLon) * cos($mLat);
      $yg = $r * sin($mLon) * cos($mLat);
      $zg = $r * sin($mLat);
    }

    $ecl = self::earthObl1 + self::earthObl2 * $T;

    $xe = $xg;
    $ye = $yg * cos($ecl) - $zg * sin($ecl);
    $ze = $yg * sin($ecl) + $zg * cos($ecl);

    $RA = atan2($ye, $xe);
    $Dec = atan2($ze, sqrt($xe * $xe + $ye * $ye));
    $HA = deg2rad($this->meanSiderealTime($time) * 15) - $RA;
    $B = deg2rad($this->latitude);

    // Azimuth
    $A = rad2deg(atan2(-sin($HA), tan($Dec) * cos($B) - sin($B) * cos($HA)));

    // Azimuth is not negative
    $A += ($A < 0) * 360;

    return $A;
  }

  /**
   * Calculates the prayer times.
   *
   * @TODO: API change.
   */
  function getTimes($time = NULL) {
    !is_int($time) ? $time = time() : NULL;
    $basetime = $this->basetime($time);

    $times = array(
      $basetime,
      $this->fajr($basetime),
      $this->shurooq($basetime),
      $this->dhohr($basetime),
      $this->asr($basetime),
      $this->maghrib($basetime),
      $this->isha($basetime),
      $this->midnight($basetime + 86400),
    );

    $adjustMins = array_values($this->calcSettings['adjustMins']);
    for ($i = 0; $i < 6; $i++) {
      $times[$i + 1] += $adjustMins[$i] * 60;
    }

    return $times;
  }

  /**
   * Calculates the time for fajr.
   *
   * @TODO: API change.
   */
  protected function fajr($basetime) {
    $fajr = $this->midday($basetime);
    $angle = deg2rad($this->calcSettings['angle']['fajr']);
    $fajr -= $this->sunTime($angle, $fajr);
    $fajr -= $this->eot($fajr);
    return $fajr;
  }

  /**
   * Calculates the time for shurooq.
   *
   * @TODO: API change.
   */
  protected function shurooq($basetime) {
    $shurooq = $this->midday($basetime);
    $angle = deg2rad(self::sunset);
    $shurooq -= $this->sunTime($angle, $shurooq);
    $shurooq -= $this->eot($shurooq);
    return $shurooq;
  }

  /**
   * Calculates the time for dhohr.
   *
   * @TODO: API change.
   */
  protected function dhohr($basetime) {
    $dhohr = $this->midday($basetime);
    $dhohr -= $this->eot($dhohr);
    return $dhohr;
  }

  /**
   * Calculates the time for asr.
   *
   * @TODO: API change.
   */
  protected function asr($basetime) {
    $asr = $this->midday($basetime);
    $D = $this->declination($asr);
    $F = $this->calcSettings['angle']['asr'] + 1;
    $angle = $this->acot2($F + tan(deg2rad($this->latitude) - $D), 1);
    $asr += $this->sunTime($angle, $asr);
    $asr -= $this->eot($asr);
    return $asr;
  }

  /**
   * Calculates the time for maghrib.
   *
   * @TODO: API change.
   */
  protected function maghrib($basetime) {
    $maghrib = $this->midday($basetime);
    $angle = deg2rad(self::sunset);
    $maghrib += $this->sunTime($angle, $maghrib);
    $maghrib -= $this->eot($maghrib);
    return $maghrib;
  }

  /**
   * Calculates the time for isha.
   *
   * @TODO: API change.
   */
  protected function isha($basetime) {
    $isha = $this->midday($basetime);
    $angle = deg2rad($this->calcSettings['angle']['isha']);
    $isha += $this->sunTime($angle, $isha);
    $isha -= $this->eot($isha);
    $isha += $this->calcSettings['ishaMinutes'] * 60;
    return $isha;
  }

  /**
   * Calculates the sun's altitude.
   *
   * @see: https://en.wikipedia.org/wiki/Horizontal_coordinate_system
   */
  protected function sunAltitude($time) {
    $T = ($this->unix2JD($time) - self::J2000EpochJD) / 36525;

    // Solar coordinates
    $K = self::earthObl1 + self::earthObl2 * $T;
    $Rs = $this->rightAscension($T);

    // Horizontal coordinates
    $H = deg2rad($this->meanSiderealTime($time) * 15) - $Rs;
    $Ds = $this->declination($time);
    $B = deg2rad($this->latitude);

    $h = rad2deg(asin(sin($B) * sin($Ds) + cos($B) * cos($Ds) * cos($H)));

    return $h;
  }

  /**
   * Calculates how long it takes for the sun to reach a given altitude.
   */
  protected function sunTime($angle, $time) {
    $B = deg2rad($this->latitude);
    $sunTime = 0;
    for ($i = 0; $i < 2; $i++) { // 2 iterations are much more accurate than 1
      $D = $this->declination($time + $sunTime);
      $sunTime = acos((sin($angle) - sin($D) * sin($B)) / (cos($D) * cos($B)));
      $sunTime *= 43200 / M_PI;
    }
    return $sunTime;
  }

  /**
   * Calculates the equation of time.
   *
   * @see: https://en.wikipedia.org/wiki/Equation_of_time
   */
  protected function eot($time) {
    $T = ($this->unix2JD($time) - self::J2000EpochJD) / 36525;

    // Solar coordinates
    $Lo = self::sunLng1 + self::sunLng2 * $T;
    $Rs = $this->rightAscension($T);

    // Equation of time
    $deltaT = ($Lo - $Rs);
    // -PI <= deltaT < PI
    $deltaT -= floor(($deltaT + M_PI) / M_PI / 2) * 2 * M_PI;
    // Convert radians to seconds
    $deltaT *= 43200 / M_PI;

    return $deltaT;
  }

  /**
   * Calculates the mean sidereal time.
   *
   * @see: https://en.wikipedia.org/wiki/Sidereal_time
   */
  protected function meanSiderealTime($time) {
    $D = $this->unix2JD($time) - self::J2000EpochJD;

    $Do = -round(-$D) - 0.5;
    $tUT = 24 * ($D - $Do);
    $T = $D / 36525;
    $S = self::earthSrt1 + self::earthSrt2 * $Do + self::earthSrt3 * $tUT +
      self::earthSrt4 * pow($T, 2) + $this->longitude / 15;

    return $S;
  }

  /**
   * Calculates the declination of the sun.
   *
   * @see: https://en.wikipedia.org/wiki/Declination
   */
  protected function declination($time) {
    $T = ($this->unix2JD($time) - self::J2000EpochJD) / 36525;
    $K = self::earthObl1 + self::earthObl2 * $T;
    $Ls = $this->sunTrueLongitude($T);
    $Ds = asin(sin($Ls) * sin($K));
    return $Ds;
  }

  /**
   * Calculates the right ascension of the sun.
   *
   * @see: https://en.wikipedia.org/wiki/Right_ascension
   */
  protected function rightAscension($T) {
    $Ls = $this->sunTrueLongitude($T);
    $K = self::earthObl1 + self::earthObl2 * $T;
    $Rs = atan2(sin($Ls) * cos($K), cos($Ls));
    return $Rs;
  }

  /**
   * Calculates the sun's true longitude.
   */
  protected function sunTrueLongitude($T) {
    $Lo = self::sunLng1 + self::sunLng2 * $T;
    $Mo = self::sunAno1 + self::sunAno2 * $T;
    $C = (self::sunCenter1 + self::sunCenter2 * $T) * sin($Mo) +
      self::sunCenter3 * sin(2 * $Mo);
    $Ls = $Lo + $C;
    return $Ls;
  }
}

