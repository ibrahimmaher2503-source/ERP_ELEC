# Design consistency matrix

| Surface | Observed pattern | Consistency result | Recommendation |
|---|---|---|---|
| Shell / navigation | White right sidebar, pale-blue main surface, royal-blue active state | Consistent in 50 fresh captures | Preserve shell; reduce navigation cognitive load and remove dead controls. |
| Page headings | Large navy Arabic heading, breadcrumb row and muted subtitle | Mostly consistent | Keep the hierarchy; shorten long breadcrumb/chip rows on mobile. |
| Primary actions | Royal-blue filled buttons | Consistent | Reserve blue for the main action and avoid competing green selected states. |
| Cards | Thin blue-gray border, white surface, rounded corners | Consistent | Keep the card primitive; tune empty-state height so screens do not feel vacant. |
| Tables | Pale-blue header, blue text, compact rows | Consistent desktop; weak mobile adaptation | Switch dense financial tables to priority columns/cards below tablet width. |
| Filters | White controls inside pale-blue/gradient filter panels | Mostly consistent | Use one mobile filter collapse pattern and label all fields. |
| Tabs / chips | Blue active tabs in some modules, green selected actions in others | Inconsistent semantic palette | Document semantic tokens and apply them by meaning. |
| Forms | RTL labels and full-width controls on mobile | Good in customer create; label coverage incomplete in inventory | Add explicit labels to `search` and `productSearch`; keep form grouping. |
| Empty states | Centered icon, message and explanatory text | Clear but oversized on dashboard | Add a truthful next action or compact the container when no data exists. |
| Status indicators | Green success, orange warning, blue neutral | Understandable | Add text/icon redundancy and confirm contrast in every state. |
| Iconography | Flux outline icons, mostly consistent | Consistent; workshop and maintenance both use wrench icon | Differentiate module icons where the same glyph obscures module identity. |

The approved direction is light RTL with a white right sidebar and royal-blue actions. The fresh evidence supports that foundation. The executive dashboard is the major visual gap because it remains an empty-state shell rather than a distinct analytics experience.
