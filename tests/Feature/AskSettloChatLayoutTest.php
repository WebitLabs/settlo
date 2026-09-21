<?php

/**
 * L8: the chat pane must be able to shrink below 20rem on narrow phones; the
 * 20rem floor only applies once the side panes can sit next to it.
 */
it('only keeps the 20rem chat minimum width when the side panes are inline', function () {
    $source = (string) file_get_contents(resource_path('js/Pages/AskSettlo/ChatPanel.jsx'));

    expect(preg_match_all('/(?<![\w:@-])min-w-\[20rem\]/', $source))->toBe(0)
        ->and($source)->toContain('@3xl:min-w-[20rem]')
        ->and($source)->toMatch('/<section className="[^"]*\bmin-w-0\b/');
});

it('wraps long words in chat answers instead of overflowing', function () {
    $source = (string) file_get_contents(resource_path('js/Pages/AskSettlo/ChatPanel.jsx'));

    expect($source)->toContain('className="break-words whitespace-pre-wrap"');
});
