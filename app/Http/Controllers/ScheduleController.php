<?php

namespace App\Http\Controllers;

use App\Http\Algorithm\GeneticAlgo;
use App\Models\Schedule;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    public function generateSchedule(GeneticAlgo $geneticAlgorithm)
    {
        try {
            $result = $geneticAlgorithm->run();
            return response()->json(['message' => $result], 200);
        } catch (\Throwable $th) {
            return response()->json(['message' => $th->getMessage()], 500);
        }
    }

    // Endpoint untuk mendapatkan jadwal
    public function getSchedules()
    {
        $schedules = Schedule::with('employee:id,name')->get();
        return response()->json($schedules, 200);
    }

    // Endpoint untuk mendapatkan jadwal berdasarkan tanggal tertentu
    public function getSchedulesByDate(Request $request)
    {
        $request->validate([
            'date' => 'required|date',
        ]);

        $schedules = Schedule::with('employee:id,name')
            ->where('work_date', $request->date)
            ->get();

        return response()->json($schedules, 200);
    }
}
