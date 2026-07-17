<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accept invitation · Settlo</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: #f3f4f6;
            color: #111827;
            display: flex;
            min-height: 100vh;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .card {
            background: #fff;
            width: 100%;
            max-width: 520px;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,.08);
            padding: 32px;
        }
        .brand { font-weight: 700; color: #0F6E56; letter-spacing: .02em; }
        h1 { font-size: 22px; margin: 12px 0 4px; }
        p.lead { color: #4b5563; margin: 0 0 24px; line-height: 1.5; }
        label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 8px; }
        select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #d1d5db;
            border-radius: 10px;
            font-size: 15px;
            background: #fff;
        }
        button {
            margin-top: 24px;
            width: 100%;
            padding: 13px 16px;
            border: 0;
            border-radius: 10px;
            background: #0F6E56;
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
        }
        button:hover { background: #0c5946; }
        .error { color: #b91c1c; font-size: 13px; margin-top: 8px; }
        .empty { color: #6b7280; font-size: 14px; background: #f9fafb; border-radius: 10px; padding: 16px; }
        .foot { margin-top: 20px; font-size: 12px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">SETTLO</div>
        <h1>{{ $firmName }} wants to manage your books</h1>
        <p class="lead">
            Choose which business to grant access to. The firm will be able to review that
            business's invoices, expenses and tax estimates. You can revoke access at any time.
        </p>

        @if ($entities->isEmpty())
            <div class="empty">
                You do not have any businesses to assign yet. Finish setting up a business first,
                then re-open this invitation link.
            </div>
        @else
            <form method="POST" action="{{ route('firm-invitations.store', ['token' => $token]) }}">
                @csrf
                <label for="business_entity_id">Business to assign</label>
                <select id="business_entity_id" name="business_entity_id" required>
                    @foreach ($entities as $entity)
                        <option value="{{ $entity->getKey() }}">{{ $entity->name }}</option>
                    @endforeach
                </select>
                @error('business_entity_id')
                    <div class="error">{{ $message }}</div>
                @enderror
                <button type="submit">Grant access to {{ $firmName }}</button>
            </form>
        @endif

        <div class="foot">Invited as {{ $invitation->email }}</div>
    </div>
</body>
</html>
