<?php

namespace App\Http\Algorithm;

use App\Models\Constraint;
use App\Models\Employee;
use App\Models\Schedule;

class GeneticAlgo
{
    protected $populationSize = 10;
    protected $mutationRate = 0.1;
    protected $crossoverRate = 0.8;
    protected $generations = 50;
    protected $days = 6;

    public function run()
    {
        $employees = Employee::all();
        if ($employees->isEmpty()) {
            throw new \Exception('Tidak ada data karyawan.');
        }

        $constraints = Constraint::first();
        if (!$constraints) {
            throw new \Exception('Constraint belum diatur.');
        }

        $population = $this->initializePopulation($employees, $constraints);

        for ($generation = 0; $generation < $this->generations; $generation++) {
            $fitnessScores = $this->evaluateFitness($population, $constraints);

            $newPopulation = [];
            while (count($newPopulation) < $this->populationSize) {
                $parent1 = $this->selectParent($population, $fitnessScores);
                $parent2 = $this->selectParent($population, $fitnessScores);

                $offspring = (rand(0, 100) / 100 <= $this->crossoverRate)
                    ? $this->crossover($parent1, $parent2, $constraints)
                    : [$parent1, $parent2];

                foreach ($offspring as $child) {
                    if (rand(0, 100) / 100 <= $this->mutationRate) {
                        $child = $this->mutate($child, $constraints);
                    }

                    if ($this->validateSchedule($child, $constraints)) {
                        $newPopulation[] = $child;
                    }
                }
            }

            $population = array_slice($newPopulation, 0, $this->populationSize);
        }

        $bestSolution = $this->getBestSolution($population, $fitnessScores);
        $this->saveSchedule($bestSolution, $employees);

        return "Penjadwalan berhasil dibuat!";
    }

    protected function initializePopulation($employees, $constraints)
    {
        $population = [];
        $employeeIds = $employees->pluck('id')->toArray();
        $maxWFHPerEmployee = $constraints->max_wfh_per_employee;

        for ($i = 0; $i < $this->populationSize; $i++) {
            $individual = $this->createInitialSchedule($employeeIds, $constraints, $maxWFHPerEmployee);
            $population[] = $individual;
        }

        return $population;
    }

    protected function createInitialSchedule($employeeIds, $constraints, $maxWFHPerEmployee)
    {
        $individual = [];
        $employeeWFHCounts = array_fill_keys($employeeIds, 0);

        foreach ($employeeIds as $employeeId) {
            for ($day = 0; $day < $this->days; $day++) {
                $individual[$employeeId][$day] = 0;
            }
        }

        // Awali dengan pemerataan: Semua karyawan mendapat minimal 1 WFH
        foreach ($employeeIds as $employeeId) {
            $this->assignWFH($individual, $employeeId, $employeeWFHCounts, $constraints);
        }

        // Isi jadwal secara acak
        for ($day = 0; $day < $this->days; $day++) {
            while (array_sum(array_column($individual, $day)) < $constraints->max_wfh_per_day) {
                $candidate = $employeeIds[array_rand($employeeIds)];
                if ($employeeWFHCounts[$candidate] < $maxWFHPerEmployee) {
                    $individual[$candidate][$day] = 1;
                    $employeeWFHCounts[$candidate]++;
                }
            }
        }

        return $individual;
    }

    protected function assignWFH(&$schedule, $employeeId, &$employeeWFHCounts, $constraints)
    {
        $randomDay = rand(0, $this->days - 1);
        while (array_sum(array_column($schedule, $randomDay)) >= $constraints->max_wfh_per_day) {
            $randomDay = rand(0, $this->days - 1);
        }
        $schedule[$employeeId][$randomDay] = 1;
        $employeeWFHCounts[$employeeId]++;
    }

    protected function getBestSolution($population, $fitnessScores)
    {
        $bestIndex = array_search(max($fitnessScores), $fitnessScores);
        return $population[$bestIndex];
    }

    protected function evaluateFitness($population, $constraints)
    {
        $fitnessScores = [];
        $meanWFH = $constraints->max_wfh_per_day * $this->days / count($population[0]);

        foreach ($population as $individual) {
            $score = 0;
            $dailyCounts = array_fill(0, $this->days, 0);
            $employeeWFHCounts = [];

            foreach ($individual as $employeeId => $schedule) {
                $employeeWFHCounts[$employeeId] = array_sum($schedule);

                foreach ($schedule as $day => $isWFH) {
                    if ($isWFH) {
                        $dailyCounts[$day]++;
                    }
                }
            }

            foreach ($dailyCounts as $dailyCount) {
                $score -= abs($dailyCount - $constraints->max_wfh_per_day);
            }

            foreach ($employeeWFHCounts as $count) {
                $score -= pow(abs($count - $meanWFH), 2);
            }

            $fitnessScores[] = $score;
        }

        return $fitnessScores;
    }


    protected function selectParent($population, $fitnessScores)
    {
        $totalFitness = array_sum($fitnessScores);
        $random = rand(0, $totalFitness);

        foreach ($population as $index => $individual) {
            $random -= $fitnessScores[$index];
            if ($random <= 0) {
                return $individual;
            }
        }

        return $population[0];
    }

    protected function crossover($parent1, $parent2, $constraints)
    {
        $child1 = [];
        $child2 = [];
        $employeeIds = array_keys($parent1);
        $days = count($parent1[$employeeIds[0]]);

        foreach ($employeeIds as $employeeId) {
            // Tentukan titik crossover
            $crossoverPoint = rand(1, $days - 1);

            // Gabungkan jadwal dari kedua orang tua
            $child1[$employeeId] = array_merge(
                array_slice($parent1[$employeeId], 0, $crossoverPoint),
                array_slice($parent2[$employeeId], $crossoverPoint)
            );
            $child2[$employeeId] = array_merge(
                array_slice($parent2[$employeeId], 0, $crossoverPoint),
                array_slice($parent1[$employeeId], $crossoverPoint)
            );
        }

        // Perbaiki distribusi jika ada karyawan tanpa WFH
        $child1 = $this->ensureFairDistribution($child1, $constraints);
        $child2 = $this->ensureFairDistribution($child2, $constraints);

        return [$child1, $child2];
    }

    protected function ensureFairDistribution($schedule, $constraints)
    {
        $employeeIds = array_keys($schedule);
        $days = count($schedule[$employeeIds[0]]);
        $employeeWFHCounts = array_map(function ($days) {
            return array_sum($days);
        }, $schedule);

        // Cari karyawan yang belum mendapatkan WFH
        $employeesWithoutWFH = array_keys(array_filter($employeeWFHCounts, fn($count) => $count === 0));

        foreach ($employeesWithoutWFH as $employeeId) {
            // Cari hari yang kurang dari 4 WFH
            for ($day = 0; $day < $days; $day++) {
                $dailyWFH = array_sum(array_column($schedule, $day));
                if ($dailyWFH < $constraints->max_wfh_per_day) {
                    $schedule[$employeeId][$day] = 1; // Tetapkan WFH
                    break;
                }
            }
        }

        return $schedule;
    }



    protected function mutate($individual, $constraints)
    {
        $employeeIds = array_keys($individual);
        $days = count($individual[$employeeIds[0]]);
        $maxWFHPerEmployee = $constraints->max_wfh_per_employee;

        // Cari karyawan yang belum mendapatkan WFH
        $employeeWFHCounts = array_map(fn($schedule) => array_count_values($schedule)[1] ?? 0, $individual);
        $employeesWithoutWFH = array_keys(array_filter($employeeWFHCounts, fn($count) => $count === 0));

        if (!empty($employeesWithoutWFH)) {
            foreach ($employeesWithoutWFH as $employeeId) {
                // Tetapkan minimal 1 hari WFH secara acak
                $randomDay = rand(0, $days - 1);
                while (array_sum(array_column($individual, $randomDay)) >= $constraints->max_wfh_per_day) {
                    $randomDay = rand(0, $days - 1);
                }
                $individual[$employeeId][$randomDay] = 1;
            }
        }

        // Mutasi jadwal lain secara acak
        foreach ($individual as $employeeId => $schedule) {
            if (rand(0, 100) < $this->mutationRate * 100) {
                $randomDay = rand(0, $days - 1);
                $individual[$employeeId][$randomDay] = 1 - $schedule[$randomDay];
            }
        }

        return $individual;
    }

    protected function validateSchedule($schedule, $constraints)
    {
        $employeeIds = array_keys($schedule);
        $days = count($schedule[$employeeIds[0]]);

        // Periksa setiap karyawan memiliki setidaknya 1 WFH
        foreach ($employeeIds as $employeeId) {
            if (array_sum($schedule[$employeeId]) === 0) {
                return false;
            }
        }

        // Periksa setiap hari ada tepat 4 WFH
        for ($day = 0; $day < $days; $day++) {
            if (array_sum(array_column($schedule, $day)) !== $constraints->max_wfh_per_day) {
                return false;
            }
        }

        return true;
    }


    protected function saveSchedule($solution, $employees)
    {
        $employeeIds = $employees->pluck('id')->toArray();

        foreach ($solution as $employeeId => $schedule) {
            if (!in_array($employeeId, $employeeIds)) {
                \Log::error("Invalid Employee ID: $employeeId. Schedule not saved.");
                continue; // Lewati jika ID tidak valid
            }

            foreach ($schedule as $day => $isWFH) {
                Schedule::create([
                    'employee_id' => $employeeId,
                    'work_date' => now()->startOfWeek()->addDays($day),
                    'is_wfh' => $isWFH
                ]);
            }
        }
    }
}
