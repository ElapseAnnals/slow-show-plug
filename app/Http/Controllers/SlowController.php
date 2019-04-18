<?php

namespace App\Http\Controllers;


use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class SlowController extends Controller
{
    /**
     * slowquery.log to slow/slow_show.log
     */
    public function index()
    {
        ini_set('memory_limit', '-1');
        ini_set('max_execution_time', '-1');

        $file_path = storage_path('app/temp/slow/slowquery.log');
        $myfile = fopen($file_path, "r") or die("Unable to open file!");
        $i = -1;
        while (!feof($myfile)) {
            $now_line_str = fgets($myfile);
            $type_key = substr($now_line_str, 0, 6);
            switch ($type_key) {
                case '# Time':
                    $i++;
                    $temp_arr[$i]['Time'] = date('Y-m-d H:i:s', strtotime(trim(str_replace('# Time:', '', $now_line_str))));
                    break;
                case '# User':
                    $temp_user = explode('  ', trim(str_replace('#', '', $now_line_str)));
                    $temp_arr[$i]['User'] = explode(' ', trim($temp_user[0]))[1];
                    $temp_arr[$i]['Host'] = trim($temp_user[1], '[|]');
                    if (isset($temp_user[2])) {
                        $explode_id = explode(' ', trim($temp_user[2]));
                        if ($explode_id && is_array($explode_id) && isset($explode_id[1])) {
                            $temp_arr[$i]['Id'] = $explode_id[1];
                        }else{
                            Log::error($explode_id);
                        }
                    }
                    break;
                case '# Quer':
                    $temp_query = explode('  ', trim(str_replace('#', '', $now_line_str)));
                    $temp_arr[$i]['Query_time'] = explode(' ', trim($temp_query[0]))[1];
                    $temp_lock = explode(' ', trim($temp_query[1]));
                    $temp_arr[$i]['Lock_time'] = $temp_lock[1];
                    $temp_arr[$i]['Rows_sent'] = $temp_lock[3];
                    $temp_arr[$i]['Rows_examined'] = explode(' ', trim($temp_query[2]))[1];
                    break;
                case 'SET ti':
                    $temp_arr[$i]['set_timestamp'] = str_replace("\r\n", "", $now_line_str);;
                    break;
                case 'use b5':
                case 'use bi':
                    $temp_arr[$i]['use_database'] = str_replace("\r\n", "", $now_line_str);
                    break;
                default:
                    if (!isset($temp_arr[$i]['sql'])) {
                        $temp_arr[$i]['sql'] = '';
                    }
                    $temp_arr[$i]['sql'] .= ' ' . trim($now_line_str);
            }
            if (isset($temp_arr[$i]['sql'])) {
                $temp_arr[$i]['hash'] = md5($temp_arr[$i]['sql']);
            }
            if (199497 == $i) {
                break;
            }
        }
        fclose($myfile);
        $temp_collect = collect($temp_arr);
        unset($temp_arr);
        $temp_group = $temp_collect->groupBy('hash');
        foreach ($temp_group as $key => $value) {
            $value = json_decode(json_encode($value), true);
            if (empty($value)) {
                continue;
            }
            $array_query_time = array_column(array_values($value), 'Query_time');
            $array_time = array_column(array_values($value), 'Time');
            if ($array_query_time) {
                $temp_count['max_query_time'] = max($array_query_time);
            }
            if ($array_time) {
                $temp_count['max_time'] = max($array_time);
            }
            $temp_count['count'] = count($value);
            $temp_count['hash'] = $key;
            if (isset($value[0]['sql'])) {
                $temp_count['sql'] = $value[0]['sql'];
            }
            $temp_counts[] = $temp_count;
        }
        unset($temp_group);
        $temp_counts = array_filter($temp_counts, function ($value) {
            if (30 < $value['count']) {
                return true;
            }
        });
        $temp_count_collect = collect($temp_counts);
        unset($temp_counts);
        $sorted = $temp_count_collect->sortByDesc('count');
        Storage::disk('local')->put('slow/slow_show.log', $sorted);
    }
}
