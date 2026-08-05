<?php

namespace App\Support;

/**
 * Merges validated teeboxes + green centers into a course's existing
 * layout_data while PRESERVING every other key (e.g. the vendor `golftraxx`
 * blob) and reproducing the canonical stored shape exactly:
 *
 *   - teebox holes keyed "hole-N"; par/length are STRINGS, handicap an INT
 *   - greenCenters an OBJECT "hole-N" => {lat: float, lng: float} (raw floats)
 *
 * This is the single writer for the editor so the data stays byte-consistent
 * with the imported/scraped dataset.
 */
class CourseLayoutWriter
{
    /**
     * @param  array<string,mixed>|null  $existing  current layout_data (decoded)
     * @param  array<int,array<string,mixed>>  $teeboxes
     * @param  array<int,array<string,mixed>>  $greenCenters  [{hole,lat,lng}]
     * @return array<string,mixed>
     */
    public static function merge(?array $existing, array $teeboxes, array $greenCenters, ?int $holeCount): array
    {
        $data = is_array($existing) ? $existing : [];

        // ---- teeboxes ----
        $rebuilt = [];
        $maxHoles = 0;

        foreach (array_values($teeboxes) as $i => $tb) {
            $holes = [];
            foreach (($tb['holes'] ?? []) as $h) {
                $n = (int) ($h['hole'] ?? 0);
                if ($n < 1) {
                    continue;
                }
                $hole = [];
                if (self::filled($h['par'] ?? null)) {
                    $hole['par'] = (string) (int) $h['par'];
                }
                if (self::filled($h['length'] ?? null)) {
                    $hole['length'] = (string) (int) $h['length'];
                }
                if (self::filled($h['handicap'] ?? null)) {
                    $hole['handicap'] = (int) $h['handicap'];
                }
                if ($hole !== []) {
                    $holes['hole-'.$n] = $hole;
                }
            }
            $maxHoles = max($maxHoles, count($holes));

            $tee = [
                'order' => $i,
                'name' => (string) ($tb['name'] ?? ''),
                'slope' => self::filled($tb['slope'] ?? null) ? (int) $tb['slope'] : null,
                'courseRating' => self::filled($tb['courseRating'] ?? null) ? (float) $tb['courseRating'] : null,
                'totalYardage' => self::filled($tb['totalYardage'] ?? null) ? (int) $tb['totalYardage'] : null,
                'holes' => (object) $holes, // always encode as an object
            ];
            if (self::filled($tb['color'] ?? null)) {
                $tee['color'] = (string) $tb['color'];
            }
            if (self::filled($tb['secondaryColor'] ?? null)) {
                $tee['secondaryColor'] = (string) $tb['secondaryColor'];
            }

            $rebuilt[] = $tee;
        }

        $data['teeboxes'] = $rebuilt;
        $data['hole_count'] = $holeCount ?: ($maxHoles ?: ($data['hole_count'] ?? null));

        // ---- green centers ----
        $gc = [];
        foreach ($greenCenters as $g) {
            $n = (int) ($g['hole'] ?? 0);
            if ($n < 1 || ! isset($g['lat'], $g['lng'])) {
                continue;
            }
            $gc['hole-'.$n] = ['lat' => (float) $g['lat'], 'lng' => (float) $g['lng']];
        }

        if ($gc !== []) {
            $data['greenCenters'] = (object) $gc;
            $data['greenCentersSource'] = 'manual';
        } else {
            unset($data['greenCenters'], $data['greenCentersSource']);
        }

        return $data;
    }

    private static function filled(mixed $v): bool
    {
        return $v !== null && $v !== '';
    }
}
