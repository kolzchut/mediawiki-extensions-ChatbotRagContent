Extension:ChatbotRagContent
==============================

This extension is used to notify an external RAG database of content changes,
and supply an API for retrieval of adapted content for that database.
This extension was written specifically for use in a joint chatbot with RAG project between
Kol-Zchut and Webix, and as such the data format is probably not universally useful.

[Document update flow by Webix](https://docs.google.com/document/d/1igsU6L2FJpWn6rYBwJfLLXwGUYq0vJpmvh6VZv86cn8/edit#heading=h.g0tflggr4vs3)


## Configuration options
| Name                           | values              | Role                                           |
|--------------------------------|---------------------|------------------------------------------------|
| $wgChatbotRagContentPingURL    | URL                 | Pinged on every content update                 |
| $wgChatbotRagContentNamespaces | Array of namespaces | Which namespaces this extension should work in |

### $wgChatbotRagContentPingURL
The data will be sent as JSON to the specified URL, in the following format:
```json
{
    "page_id": 3,
    "rev_id": 13500,
    "callback_uri": "https://example.com/w/rest.php/cbragcontent/v0/page_id/3"
}
```
## API for content retrieval
The extension provides a MediaWiki REST API endpoint, in this form:
`https://example.com/w/rest.php/cbragcontent/v0/page_id/3`

## Scenarios handled
1. Pages updated
2. New pages created directly in an allowed namespace
3. Pages moved in/out of allowed namespaces
