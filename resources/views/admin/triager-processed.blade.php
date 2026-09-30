<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Processed Requests — {{ $printedAt->format('M j, Y') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', sans-serif; padding: 2rem; }
        h1 { font-size: 1.5rem; margin-bottom: .25rem; }
        .subtitle { color: #64748b; margin-bottom: 1.5rem; }
        table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        th, td { padding: .5rem .75rem; border: 1px solid #e2e8f0; text-align: left; }
        th { background: #f1f5f9; font-weight: 600; }
        .badge { padding: .2rem .5rem; border-radius: 4px; font-size: .75rem; font-weight: 600; }
        .badge-approved { background: #dcfce7; color: #166534; }
        .badge-denied { background: #fee2e2; color: #991b1b; }
        .badge-suspend { background: #fef3c7; color: #92400e; }
        .badge-default { background: #e0e7ff; color: #3730a3; }
        .footer { margin-top: 1.5rem; color: #94a3b8; font-size: .8rem; }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <h1>Processed Appointment Requests</h1>
    <p class="subtitle">{{ $printedAt->format('F j, Y') }} &middot; QMMC Triager Dashboard</p>

    @if (empty($processedToday))
        <p>No processed requests today.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Patient</th>
                    <th>Hospital No</th>
                    <th>Mode</th>
                    <th>Action</th>
                    <th>Processed At</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($processedToday as $item)
                    <tr>
                        <td>{{ $item['patient_name'] }}</td>
                        <td>{{ $item['hospital_number'] }}</td>
                        <td>{{ $item['id'] ? 'Face-to-Face' : 'Telemedicine' }}</td>
                        <td>
                            <span class="badge badge-{{ strtolower(str_replace(' ', '-', $item['triager_action'] ?? 'default')) }}">
                                {{ $item['triager_action'] ?? 'Processed' }}
                            </span>
                        </td>
                        <td>{{ $item['requested_at']?->format('h:i A') ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="footer">Printed on {{ $printedAt->format('M j, Y h:i A') }}</p>

    <button class="btn btn-primary no-print" onclick="window.print()">Print</button>
</body>
</html>
