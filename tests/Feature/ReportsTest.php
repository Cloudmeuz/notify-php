<?php

test('reports()->daily() sends from/to as Y-m-d query params and returns the data rows', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'data' => [
            ['date' => '2026-09-15', 'channel' => 'sms', 'count' => 10, 'amount' => '1200.00'],
        ]]),
    ], $history);

    $rows = $client->reports()->daily(new DateTime('2026-09-10'), new DateTime('2026-09-16'));

    expect($rows)->toBe([
        ['date' => '2026-09-15', 'channel' => 'sms', 'count' => 10, 'amount' => '1200.00'],
    ]);

    $request = $history[1]['request'];
    expect((string) $request->getUri())->toContain('/reports/daily');
    parse_str($request->getUri()->getQuery(), $query);
    expect($query)->toBe(['from' => '2026-09-10', 'to' => '2026-09-16']);
});

test('reports()->monthly() omits from/to entirely when not given', function () {
    $history = [];
    $client = makeMockedClient([
        jsonResponse(200, tokenResponseBody()),
        jsonResponse(200, ['success' => true, 'data' => []]),
    ], $history);

    $client->reports()->monthly();

    $request = $history[1]['request'];
    expect((string) $request->getUri())->toContain('/reports/monthly');
    expect($request->getUri()->getQuery())->toBe('');
});
