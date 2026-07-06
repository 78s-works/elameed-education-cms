<?php

return [
    // Points awarded per event (FR-M19).
    'lesson_points' => (int) env('GAMIFICATION_LESSON_POINTS', 5),
    'exam_points' => (int) env('GAMIFICATION_EXAM_POINTS', 20),
];
