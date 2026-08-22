Extension:ChatbotRagContent
==============================

This extension is used to notify an external RAG database of content changes,
and supply an API for retrieval of adapted content for that database.
This extension was written specifically for use in a joint chatbot with RAG project between
Kol-Zchut and Webix, and as such, the data format is probably not universally useful.

[Document update flow by Webix](https://docs.google.com/document/d/1igsU6L2FJpWn6rYBwJfLLXwGUYq0vJpmvh6VZv86cn8/edit#heading=h.g0tflggr4vs3)

## Configuration options
| Name                                     | values                 | Role                                                         |
|------------------------------------------|------------------------|--------------------------------------------------------------|
| $wgChatbotRagContentPingURL              | URL                    | Pinged on every content update                               |
| $wgChatbotRagContentNamespaces           | Array of namespaces    | Which namespaces this extension should work in               |
| $wgChatbotRagContentArticleTypeBlocklist | array of article types | Article types to be ignored                                  |
| $wgChatbotRagContentTitleAllowlist	    | array of titles        | Titles that override namespace and article type restrictions |
| $wgChatbotRagContentPingImmediateRetries | integer                | Extra pingback attempts within a single job run              |
| $wgChatbotRagContentPingImmediateRetryDelay | seconds             | Pause between those in-run attempts                          |
| $wgChatbotRagContentPingRetryDelays      | array of seconds       | Backoff before each queued retry (jittered); its length is the ceiling |

### $wgChatbotRagContentPingURL
The data will be sent as JSON to the specified URL, in the following format:
```json
{
     "page_id": 3,
     "revision_id": 13500,
     "callback_url": "https://example.com/w/rest.php/cbragcontent/v0/page_id/"
}
```
### Delivery and retry

A pingback that is not delivered is not merely late: nothing else re-sends it,
so the page's RAG content stays stale until somebody happens to edit it again.
The job therefore owns its own bounded retry instead of leaving failure to the
job queue, which cannot tell a transient 500 from a permanent 404.

* **Retriable** — 5xx, 408, 425, 429, and any failure with no usable HTTP status
  (DNS, connect timeout, TLS). Anything that cannot be positively identified as
  a rejection counts as retriable.
* **Terminal** — every other 4xx. The backend has rejected the notification
  itself, and an identical request can only be rejected again.

A retriable failure is retried at once inside the same run
(`$wgChatbotRagContentPingImmediateRetries`), and then, if it still fails, as a
delayed copy of the job carrying the next attempt number
(`$wgChatbotRagContentPingRetryDelays`).

Each queued delay is spread by ±10% before it is scheduled, so the default
`[300, 1800]` ladder actually fires somewhere in 270–330s and then 1620–1980s.
An outage fails every pingback in the window it lasts; without the spread all
of those retries would re-fire on the same second and arrive at the recovering
backend as one burst.

Queued retries need a job queue that supports delayed jobs; if the push is
refused the job is returned to the queue as failed and the refusal is logged at
`critical`.

Once the ceiling is reached — or immediately, on a terminal status — the job
logs `RAG pingback abandoned` at `critical` on the `ChatbotRagContent` channel,
with `reason` (`retries-exhausted` or `non-retriable-status`), `attempts`,
`status` and the page. That log line is the operator surface for a page that has
permanently dropped out of the RAG index; it is deliberately distinct from the
per-attempt `Pingback to RAG endpoint failed` error, which is expected noise.

## API for content retrieval
The extension provides a MediaWiki REST API endpoint, in this form:
`https://example.com/w/rest.php/cbragcontent/v0/page_id/3`

## Scenarios handled
1. Pages updated
2. New pages created directly in an allowed namespace
3. Pages moved in/out of allowed namespaces

## Magic Words

### __EXCLUDE_FROM_RAG__
If this magic word is present anywhere in a page, the page will be excluded from RAG content and API responses.
This is useful for hiding sensitive or irrelevant pages from the RAG system. To use, simply add `__EXCLUDE_FROM_RAG__`
anywhere in the page's wikitext. This also adds the page to the `Pages excluded from RAG` tracking category (localizable).

**Example:**
```
This page should not be included in RAG.
__EXCLUDE_FROM_RAG__
```

## Changelog

### 0.0.5
- A failed pingback is now retried with a bounded, jittered backoff instead of
  being lost, and a page that can never be delivered is reported at `critical`
  rather than dropping silently out of the RAG index.

### 0.0.4
- Modernized the hook and REST wiring to use explicit service injection instead of service-locator lookups.
- Updated the title relevance checks and integration tests to use the refactored constructors and modern TitleFactory-based calls.


