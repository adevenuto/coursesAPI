<?php

namespace App\Support;

use App\Models\Course;

/**
 * Builds a compact, human-readable summary of what changed on a course edit
 * (no full layout_data snapshots — just which fields changed and green/teebox
 * counts). Used by the editor to write course_revisions audit entries.
 */
class CourseAuditor
{
    private const SCALARS = [
        'course_name' => 'Name',
        'club_name' => 'Club',
        'address' => 'Address',
        'postal_code' => 'Postal code',
        'phone' => 'Phone',
        'website' => 'Website',
    ];

    /**
     * Capture the comparable state of a course (before or after an edit).
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Course $course): array
    {
        $data = is_array($course->layout_data) ? $course->layout_data : [];

        return [
            'course_name' => $course->course_name,
            'club_name' => $course->club_name,
            'address' => $course->address,
            'postal_code' => $course->postal_code,
            'phone' => $course->phone,
            'website' => $course->website,
            'lat' => $course->lat,
            'lng' => $course->lng,
            'teeboxes' => $data['teeboxes'] ?? [],
            'green_centers' => $course->green_centers ?? [],
        ];
    }

    /**
     * Compact change list. Empty when nothing meaningful changed.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<int, array{label:string, detail:string}>
     */
    public static function diff(array $before, array $after): array
    {
        $changes = [];

        foreach (self::SCALARS as $key => $label) {
            if (($before[$key] ?? null) !== ($after[$key] ?? null)) {
                $changes[] = ['label' => $label, 'detail' => self::val($before[$key] ?? null).' → '.self::val($after[$key] ?? null)];
            }
        }

        if ((string) ($before['lat'] ?? '') !== (string) ($after['lat'] ?? '')
            || (string) ($before['lng'] ?? '') !== (string) ($after['lng'] ?? '')) {
            $changes[] = ['label' => 'Coordinates', 'detail' => 'moved'];
        }

        if (json_encode($before['teeboxes'] ?? []) !== json_encode($after['teeboxes'] ?? [])) {
            $bn = count($before['teeboxes'] ?? []);
            $an = count($after['teeboxes'] ?? []);
            $changes[] = ['label' => 'Teeboxes', 'detail' => $bn !== $an ? "{$bn} → {$an}" : 'updated'];
        }

        if ($gc = self::greenDiff($before['green_centers'] ?? [], $after['green_centers'] ?? [])) {
            $changes[] = ['label' => 'Green centers', 'detail' => $gc];
        }

        return $changes;
    }

    private static function val(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '—';
        }
        $s = (string) $v;

        return '“'.(mb_strlen($s) > 40 ? mb_substr($s, 0, 40).'…' : $s).'”';
    }

    /**
     * @param  array<int,array{hole:int,lat:float,lng:float}>  $before
     * @param  array<int,array{hole:int,lat:float,lng:float}>  $after
     */
    private static function greenDiff(array $before, array $after): ?string
    {
        $b = [];
        foreach ($before as $g) {
            $b[$g['hole']] = [$g['lat'], $g['lng']];
        }
        $a = [];
        foreach ($after as $g) {
            $a[$g['hole']] = [$g['lat'], $g['lng']];
        }

        $added = $moved = $removed = 0;
        foreach ($a as $hole => $coord) {
            if (! isset($b[$hole])) {
                $added++;
            } elseif ($b[$hole] !== $coord) {
                $moved++;
            }
        }
        foreach ($b as $hole => $coord) {
            if (! isset($a[$hole])) {
                $removed++;
            }
        }

        $parts = [];
        if ($added) {
            $parts[] = "{$added} added";
        }
        if ($moved) {
            $parts[] = "{$moved} moved";
        }
        if ($removed) {
            $parts[] = "{$removed} removed";
        }

        return $parts ? implode(' · ', $parts) : null;
    }
}
