<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default assignee source
    |--------------------------------------------------------------------------
    |
    | Decides who a pull request is assigned to, until it is changed on the
    | Configuration page (which stores it in the "settings" table):
    |
    | "jira"      - the assignee of the Jira issue referenced in the pull
    |               request title or source branch (e.g. ABC-123). Falls back
    |               to the "bitbucket" logic when no issue key is found, the
    |               issue is unassigned, or Jira can't be reached.
    | "bitbucket" - reviewers who haven't approved yet (or the author when the
    |               pipeline failed or everyone has approved).
    |
    */

    'assignee_source' => 'jira',

    /*
    |--------------------------------------------------------------------------
    | Full sync interval
    |--------------------------------------------------------------------------
    |
    | reviewer:sync normally re-reads only what changed. Every this many
    | minutes it re-reads everything instead: all
    | repositories, members, comments, pipelines and Jira issues.
    |
    */

    'full_sync_minutes' => (int) env('REVIEWER_FULL_SYNC_MINUTES', 30),

];
