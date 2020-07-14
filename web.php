<?php

use Illuminate\Http\Request;

Route::get('/gps-tracker/batch-list/{id}', function(Request $request, $id) {
  $from = date('Y-m-d H:i:s', strtotime(str_replace("T", " ", $request->from)));
  $to = date('Y-m-d H:i:s', strtotime(str_replace("T", " ", $request->to)));
  $limit = $request->limit;
  $offset = $request->offset;

  return App\GpsTracker::where('vehicle_id', $id)
         ->where('datetime', '>=', $from)
         ->where('datetime', '<=', $to)
         ->where('speed', '>=', 1)
         ->limit($limit)
         ->offset($offset)
         ->orderBy('datetime', 'ASC')
         ->get();
});
Route::get('/gps-tracker/get-last-location/{id}', function(Request $request, $id) {
  $gps = DB::select("SELECT * FROM gps_trackers WHERE vehicle_id = $id AND speed >= 1 ORDER BY `datetime` DESC LIMIT 1");

  $data = [
    'lat' => null,
    'lng' => null
  ];

  if (count($gps) >= 1) {
    $data['lat'] = $gps[0]->latitude;
    $data['lng'] = $gps[0]->longitude;
  }

  return $data;
});
Route::get('/master-speeds', function() {
  return App\MasterSpeed::all();
});
