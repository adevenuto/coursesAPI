<x-mail::message>
# {{ $suggestion->typeLabel() }}

Someone submitted this from the course explorer.

@if ($suggestion->isCorrection())
<x-mail::table>
| | |
|:--- |:--- |
| **Course** | {{ $suggestion->course_name }} |
@if ($suggestion->club_name)
| **Club** | {{ $suggestion->club_name }} |
@endif
@if ($suggestion->locationLabel())
| **Location** | {{ $suggestion->locationLabel() }} |
@endif
| **From** | {{ $suggestion->submitter_email }}{{ $suggestion->user_id ? ' (signed in)' : '' }} |
</x-mail::table>

**What needs fixing**

<x-mail::panel>
{{ $suggestion->notes }}
</x-mail::panel>

@if ($suggestion->courseUrl())
<x-mail::button :url="$suggestion->courseUrl()">
View the course
</x-mail::button>
@endif
@else
<x-mail::table>
| | |
|:--- |:--- |
| **Course** | {{ $suggestion->course_name }} |
@if ($suggestion->club_name)
| **Club** | {{ $suggestion->club_name }} |
@endif
@if ($suggestion->locationLabel())
| **Location** | {{ $suggestion->locationLabel() }} |
@endif
@if ($suggestion->website)
| **Website** | {{ $suggestion->website }} |
@endif
| **From** | {{ $suggestion->submitter_email }}{{ $suggestion->user_id ? ' (signed in)' : '' }} |
</x-mail::table>

@if ($suggestion->notes)
**Notes**

<x-mail::panel>
{{ $suggestion->notes }}
</x-mail::panel>
@endif
@endif

{{ $suggestion->matchSummary() }}

Reply to this email to answer them directly.

<small>Suggestion #{{ $suggestion->id }} · {{ $suggestion->created_at?->toDayDateTimeString() }}</small>
</x-mail::message>
