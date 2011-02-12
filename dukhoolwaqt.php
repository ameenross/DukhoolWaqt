<?php
  // $Id$

  /*
   Copyright 2011 Ameen Ross, <http://www.dukhoolwaqt.org>

   This program is free software: you can redistribute it and/or modify
   it under the terms of the GNU General Public License as published by
   the Free Software Foundation, either version 3 of the License, or
   (at your option) any later version.

   This program is distributed in the hope that it will be useful,
   but WITHOUT ANY WARRANTY; without even the implied warranty of
   MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   GNU General Public License for more details.

   You should have received a copy of the GNU General Public License
   along with this program.  If not, see <http://www.gnu.org/licenses/>.
  */


  /**
   * @file
   * A class library to calculate Islamic prayer times, qibla and more.
   */

/**
 * Implementation of a prayer time calculation algorithm.
 */
class DukhoolWaqt {

  // Constants ================================================================

  // Ka'aba geographical coordinates
  const kaabaLat = 21.422517;
  const kaabaLng = 39.826166;

  // Default location coordinates & timezone (Masjid An-Nabawi)
  const defaultLat = 24.494647;
  const defaultLng = 39.770508;
  const defaultZone = 3; // GMT + 3

  // The sun's altitude at sunrise and sunset
  const sunset = 0.8333;

  // Altitudes of the sun used to adjust times for extreme latitudes
  const sunAltitude1 = -21;
  const sunAltitude2 = 1;

  // Used to compute sidereal time
  const earthSrt1 = 6.697374558;
  const earthSrt2 = 0.06570982441908;
  const earthSrt3 = 1.002737909350795;
  const earthSrt4 = 0.000026;

  // Used to compute obliquity of the ecliptic
  const earthObl1 = 0.40909;
  const earthObl2 = -0.0002295;

  // Used to compute the sun's mean longitude, anomaly and equation of center
  const sunLng1 = 4.89506;
  const sunLng2 = 628.33197;
  const sunAno1 = 6.24006;
  const sunAno2 = 628.30195;
  const sunCenter1 = 0.03342;
  const sunCenter2 = -0.0000873;
  const sunCenter3 = 0.000349;

  // Epochs in Julian date (difference between UTC and TT is assumed constant)
  const unixEpochJD = 2440587.500761306; // Unix epoch (1970/1/1 0:00 UTC)
  const J2000EpochJD = 2451545; // J2000 epoch (2000/1/1 12:00 TT)

  // Variables (to set) =======================================================

  private $longitude = NULL;
  private $latitude = NULL;
  private $timeZone = NULL;
  private $time = NULL;

  private $calcSettings = array(
    'angle' => array(
      'fajr' => NULL,
      'asr' => NULL, // A factor rather than an angle, here for convenience
      'maghrib' => NULL,
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

  private $methodID = NULL;

  // Quasi constants
  private $methodName = array('Karachi', 'ISNA', 'MWL', 'Makkah', 'Egypt');
  private $asrMethodName = array('Shafii', 'Hanafi');

  // Constructor ==============================================================

  function __construct($lat = NULL, $lng = NULL, $zone = NULL, $method = NULL,
$asrMethod = NULL, $adjustMins = array(NULL, NULL, NULL, NULL, NULL, NULL)) {
    $this->setLocation($lat, $lng);
    $this->setTimeZone($zone);
    $this->setMethod($method);
    $this->setAsrMethod($asrMethod);
    $this->setAdjustMins($adjustMins);
  }

  // Set functions ============================================================

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

  function setTimeZone($zone = NULL) {
    if ($zone <= 12 && $zone >= -12) {
      $this->timeZone = $zone;
    }
    else {
      $this->timeZone = self::defaultZone;
    }
  }

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
        $settings['angle']['fajr'] = 18;
        $settings['angle']['maghrib'] = self::sunset;
        $settings['angle']['isha'] = 18;
        $settings['ishaMinutes'] = 0;
        break;
      case 1: // ISNA (Islamic Society of North America)
        $settings['angle']['fajr'] = 15;
        $settings['angle']['maghrib'] = self::sunset;
        $settings['angle']['isha'] = 15;
        $settings['ishaMinutes'] = 0;
        break;
      case 2: // MWL (Muslim World League)
        $settings['angle']['fajr'] = 18;
        $settings['angle']['maghrib'] = self::sunset;
        $settings['angle']['isha'] = 17;
        $settings['ishaMinutes'] = 0;
        break;
      case 3: // Makkah (Umm al Quraa)
        $settings['angle']['fajr'] = 19;
        $settings['angle']['maghrib'] = self::sunset;
        $settings['angle']['isha'] = self::sunset;
        $settings['ishaMinutes'] = 90;
        break;
      case 4: // Egypt
        $settings['angle']['fajr'] = 19.5;
        $settings['angle']['maghrib'] = self::sunset;
        $settings['angle']['isha'] = 17.5;
        $settings['ishaMinutes'] = 0;
        break;
    }
  }

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

  // Get functions (basic) ====================================================

  function getLocation() {
    return array($this->latitude, $this->longitude);
  }

  function getLatitude() {
    return $this->latitude;
  }

  function getLongitude() {
    return $this->longitude;
  }

  function getTimeZone() {
    return $this->timeZone;
  }

  function getMethod() {
    return $this->methodName[$this->methodID];
  }

  function getMethodID() {
    return $this->methodID;
  }

  function getAsrMethod() {
    return $this->asrMethodName[$this->calcSettings['angle']['asr']];
  }

  function getAsrMethodID() {
    return $this->calcSettings['angle']['asr'];
  }

  function getAdjustMins() {
    return array_values($this->calcSettings['adjustMins']);
  }

  // Get functions (real deal) ================================================

  function getQibla() {
    return $this->qiblaAzimuth();
  }

  function getSunAzimuth($time = NULL) {
    !is_int($time) ? $time = time() : NULL;
    return $this->sunAzimuth($time);
  }

  function getTimes($time = NULL) {
    !is_int($time) ? $time = time() : NULL;
    $basetime = $this->basetime($time);
    $midnight = $this->midnight($basetime);
    $dhohr = $this->dhohr($basetime);
    $latitude = $this->latitude;

    if ($this->sunAltitude($midnight) > self::sunAltitude1) {
      echo 'Alt night before: ' . $this->sunAltitude($midnight) . '<br/>';
      $this->latitude = $this->alt2lat(self::sunAltitude1, $midnight);
      echo 'midnight: ' . $this->latitude . '<br/>';
      echo 'Alt night: ' . $this->sunAltitude($midnight) . '<br/>';
      echo 'Alt day: ' . $this->sunAltitude($dhohr) . '<br/>';
    }
    if ($this->sunAltitude($dhohr) < self::sunAltitude2) {
      echo 'Alt day before: ' . $this->sunAltitude($dhohr) . '<br/>';
      $this->latitude = $this->alt2lat(self::sunAltitude2, $dhohr);
      echo 'midday: ' . $this->latitude . '<br/>';
      echo 'Alt night: ' . $this->sunAltitude($midnight) . '<br/>';
      echo 'Alt day: ' . $this->sunAltitude($dhohr) . '<br/>';
    }

    $times = array(
      $basetime,
      $this->fajr($basetime) + $this->calcSettings['adjustMins']['fajr'],
      $this->shurooq($basetime) + $this->calcSettings['adjustMins']['shurooq'],
      $dhohr + $this->calcSettings['adjustMins']['dhohr'],
      $this->asr($basetime) + $this->calcSettings['adjustMins']['asr'],
      $this->maghrib($basetime) + $this->calcSettings['adjustMins']['maghrib'],
      $this->isha($basetime) + $this->calcSettings['adjustMins']['isha'],
    );

    $this->latitude = $latitude;
    return $times;
  }

  // Prayer times =============================================================

  private function fajr($basetime) {
    $fajr = $this->midday($basetime);
    $angle = deg2rad($this->calcSettings['angle']['fajr']);
    $fajr -= $this->sunTime($angle, $fajr);
    $fajr -= $this->eot($fajr);
    return $fajr;
  }

  private function shurooq($basetime) {
    $shurooq = $this->midday($basetime);
    $angle = deg2rad($this->calcSettings['angle']['maghrib']);
    $shurooq -= $this->sunTime($angle, $shurooq);
    $shurooq -= $this->eot($shurooq);
   return $shurooq;
  }

  private function dhohr($basetime) {
    $dhohr = $this->midday($basetime);
    $dhohr -= $this->eot($dhohr);
    return $dhohr;
  }

  private function asr($basetime) {
    $asr = $this->midday($basetime);
    $D = $this->declination($asr);
    $F = $this->calcSettings['angle']['asr'] + 1;
    $angle = -$this->acot2($F + tan(deg2rad($this->latitude) - $D));
    $asr += $this->sunTime($angle, $asr);
    $asr -= $this->eot($asr);
    return $asr;
  }

  private function maghrib($basetime) {
    $maghrib = $this->midday($basetime);
    $angle = deg2rad($this->calcSettings['angle']['maghrib']);
    $maghrib += $this->sunTime($angle, $maghrib);
    $maghrib -= $this->eot($maghrib);
    return $maghrib;
  }

  private function isha($basetime) {
    $isha = $this->midday($basetime);
    $angle = deg2rad($this->calcSettings['angle']['isha']);
    $isha += $this->sunTime($angle, $isha);
    $isha -= $this->eot($isha);
    $isha += $this->calcSettings['ishaMinutes'] * 60;
    return $isha;
  }

  // High latency correction ==================================================


  // Astronomy ================================================================

  private function qiblaAzimuth() {
    $A = deg2rad(self::kaabaLng - $this->longitude);
    $b = deg2rad(90 - $this->latitude);
    $c = deg2rad(90 - self::kaabaLat);

    $C = rad2deg(atan2(sin($A), sin($b) * $this->cot($c) - cos($b) * cos($A)));

    // Azimuth is not negative
    $C += ($C < 0) * 360;

    return $C;
  }

  private function sunAzimuth($time) {
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

  private function sunAltitude($time) {
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

  private function moonAzimuth($time) {
  }

  private function sunTime($angle, $time) {
    $B = deg2rad($this->latitude);
    $sunTime = 0;
    for ($i = 0; $i < 2; $i++) { // 2 iterations are much more accurate than 1
      $D = $this->declination($time + $sunTime);
      $sunTime = acos((sin(-$angle) - sin($D) * sin($B)) / (cos($D) * cos($B)));
      $sunTime *= 43200 / M_PI;
    }
    return $sunTime;
  }

  private function eot($time) {
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

  private function meanSiderealTime($time) {
    $D = $this->unix2JD($time) - self::J2000EpochJD;

    $Do = floor($D) - 0.5 + ($this->frac($D) >= 0.5);
    $tUT = 24 * ($D - $Do);
    $T = $D / 36525;
    $S = self::earthSrt1 + self::earthSrt2 * $Do + self::earthSrt3 * $tUT +
      self::earthSrt4 * pow($T, 2) + $this->longitude / 15;

    return $S;
  }

  private function declination($time) {
    $T = ($this->unix2JD($time) - self::J2000EpochJD) / 36525;
    $K = self::earthObl1 + self::earthObl2 * $T;
    $Ls = $this->sunTrueLongitude($T);
    $Ds = asin(sin($Ls) * sin($K));
    return $Ds;
  }

  private function rightAscension($T) {
    $Ls = $this->sunTrueLongitude($T);
    $K = self::earthObl1 + self::earthObl2 * $T;
    $Rs = atan(tan($Ls) * cos($K));
    return $Rs;
  }

  private function sunTrueLongitude($T) {
    $Lo = self::sunLng1 + self::sunLng2 * $T;
    $Mo = self::sunAno1 + self::sunAno2 * $T;
    $C = (self::sunCenter1 + self::sunCenter2 * $T) * sin($Mo) +
      self::sunCenter3 * sin(2 * $Mo);
    $Ls = $Lo + $C;
    return $Ls;
  }

  // Returns latitude where sun will reach the specified altitude
  private function alt2lat($h, $time) {
    $T = ($this->unix2JD($time) - self::J2000EpochJD) / 36525;

    $c = sin(deg2rad($h));
    $Ds = $this->declination($time);
    $H = deg2rad($this->meanSiderealTime($time) * 15) -
      $this->rightAscension($T);

    $a = sin($Ds);
    $b = cos($Ds) * cos($H);

    $phi = asin($b / sqrt(pow($a, 2) + pow($b, 2)));
    echo 'a: ' . $a . '; b: ' . $b . '; c: ' . $c . '; phi: ' . $phi . '<br/>';
    ($a * $c > 0) ? $phi = M_PI - $phi : NULL;

    $B = asin($c / sqrt(pow($a, 2) + pow($b, 2))) - $phi;
    $B -= floor(($B + M_PI_2) / M_PI) * M_PI;
    echo 'Lat: ' . rad2deg($B) . '<br/>';
    ($B * $this->latitude < 0) ? $B *= -1 : NULL;

    return rad2deg($B);
  }

  // Date / time ==============================================================

  // Unix timestamp to Julian date
  private function unix2JD($unix) {
    return self::unixEpochJD + $unix / 86400;
  }

  // Julian date to Unix timestamp
  private function JD2unix($JD) {
    return 86400 * ($JD - self::unixEpochJD);
  }

  // Get Unix timestamp for 0:00 of the day we want to calculate
  private function basetime($time) {
    $daybegin = floor($time / 86400) * 86400 - $this->timeZone * 3600;
    $midnight1 = $this->midnight($daybegin);
    $midnight2 = $this->midnight($daybegin + 86400);

    return $daybegin + 86400 * (($time > $midnight2) - ($time < $midnight1));
  }

  private function midday($basetime) {
    return $basetime + (180 + $this->timeZone * 15 - $this->longitude) * 240;
  }

  private function midnight($basetime) {
    $midnight = $basetime + ($this->timeZone * 15 - $this->longitude) * 240;
    return $midnight - $this->eot($midnight);
  }

  // Arithmetic ===============================================================

  // Result is always a positive number
  private function frac($float) {
    return $float - floor($float);
  }

  // Trigonometry =============================================================

  private function cot($rad) {
    return tan(M_PI_2 - $rad);
  }

  private function acot2($rad1, $rad2 = 1) {
    return atan2($rad2, $rad1);
  }

  // Notes ====================================================================

  /*

   */

  // Todo =====================================================================

  /*
   - Correct times for high latency
   - Calculate moon azimuth
   - Calculate moon visibility
   - Calculate sun visibility
   - Improve documentation, especially for doxygen
   */
}
