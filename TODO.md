# TODO

## Add journals (containers) to the RDF

Works are currently emitted without their journal, so the triplestore can't be queried by journal. The CSL-JSON already carries `container-title` and `ISSN` (see `format_issn()` in `shared.php`); they just never become triples.

Plan:

- Use `http://issn.org/resource/ISSN/{ISSN}` as the journal IRI, e.g. <http://issn.org/resource/ISSN/2589-3831>. The ISSN portal now serves JSON-LD at these URLs via content negotiation, which settles the earlier doubt about which ISSN URL to use.
- Type the journal as `schema:Periodical`, with `schema:name` from `container-title` and `schema:issn` for each ISSN.
- Link work → journal with `schema:isPartOf`. Volume/issue could later be modelled as `schema:PublicationVolume` / `schema:PublicationIssue`, but a direct link is enough for querying.
- Decide what to do when a record has several ISSNs (print and electronic): pick one IRI (prefer the ISSN-L if we can get it) and attach the other as `schema:issn`, or `schema:sameAs`.
- Decide what to do when there is a `container-title` but no ISSN: skip the journal, or mint a local IRI as for authors without ORCIDs.
- Update `shapes.ttl` and the design notes in `README.md` / `DESIGN.md`.
