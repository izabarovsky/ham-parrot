<?php


namespace App\Http\ViewModels;


class WeatherReportViewModel extends ViewModel
{

    public $description;

    public $temp_c;

    public $temp_f;

    public $pressure;

    public $humidity;

    public $wind_dir_cardinal;

    public $wind_mph;

    public $wind_kph;

    public $reported_at;

    public $broadcast_at;

    public $lat;

    public $lon;

    public $map_view_url;

    public function __construct($report)
    {
        $this->format($report);
    }

    private function format($report) : void
    {
        $this->description = ucfirst($report->description);
        $this->temp_c = round($report->temp, 1);
        $this->temp_f = round(($report->temp * 1.8) + 32, 1);
        $this->pressure = $report->pressure;
        $this->humidity = $report->humidity;

        $this->wind_dir_crd = self::degreesToCardinals($report->wind_dir);
        $this->wind_dir_hdg = $report->wind_dir;
        $this->wind_mph = round(($report->wind_spd * 2.237), 1);
        $this->wind_kph = round(($report->wind_spd * 3.6), 1);

        $this->lat = $report->reported_lat;
        $this->lon = $report->reported_lon;
        $this->map_view_url = "https://openweathermap.org/weathermap?basemap=map&cities=true&layer=temperature&lat={$this->lat}&lon={$this->lon}&zoom=12";

        $this->reported_at = date(self::TIME_FORMAT . ' ' . self::DATE_FORMAT, $report->reported_at);
        $this->broadcast_at = date(self::TIME_FORMAT . ' ' . self::DATE_FORMAT, $report->created_at);
    }

    /**
     * Converts degrees to compass cardinal heading.
     * @param $degrees
     * @return mixed
     */
    protected static function degreesToCardinals($degrees)
    {
        $caridnals = [
            "North",
            "North East",
            "East",
            "South East",
            "South",
            "South West",
            "West",
            "North West",
            "North"
        ];
        return $caridnals[(int)round(($degrees % 360) / 45)];
    }
}
