from aoq_worker import markup

SRC = "<h2>A</h2><p>One.{{c:c1}} Two.{{c:c2,c3}}</p><p>Three.{{c:c4}}</p><ul><li>Four</li></ul>"


def test_unchanged_translation_is_kept():
    tr = "<h2>A</h2><p>Jedan.{{c:c1}} Dva.{{c:c2,c3}}</p><p>Tri.{{c:c4}}</p><ul><li>Četiri</li></ul>"
    assert markup.place_claims(SRC, tr) == (tr, [])


def test_dropped_marker_goes_back_to_the_same_block():
    tr = "<h2>A</h2><p>Jedan.{{c:c1}} Dva.{{c:c2,c3}}</p><p>Tri.</p><ul><li>Četiri</li></ul>"
    out, restored = markup.place_claims(SRC, tr)
    assert restored == ["c4"]
    assert "<p>Tri.{{c:c4}}</p>" in out
    assert markup.claim_ids(out) == markup.claim_ids(SRC)


def test_unknown_ids_are_removed_and_missing_ones_added():
    tr = "<h2>A</h2><p>Jedan.{{c:c1}} Dva.{{c:c2,c9}}</p><p>Tri.{{c:c4}}</p><ul><li>Četiri</li></ul>"
    out, restored = markup.place_claims(SRC, tr)
    assert restored == ["c3"]
    assert "c9" not in out
    assert markup.claim_ids(out) == markup.claim_ids(SRC)


def test_different_block_count_still_covers_every_claim():
    tr = "<p>Sve u jednom pasusu.</p>"
    out, restored = markup.place_claims(SRC, tr)
    assert restored == ["c1", "c2", "c3", "c4"]
    assert markup.claim_ids(out) == markup.claim_ids(SRC)
    assert out.endswith("</p>")


def test_script_check():
    from aoq_worker.markup import foreign_script

    arabic = "<p>" + "اكتشف عجائب الشتاء في منتزه بليدينجي الطبيعي " * 5 + "Blidinje</p>"
    assert foreign_script(arabic, "ar") == ""
    mixed = arabic + "<p>" + "冒险者可以挑战未标记路线通往山峰" * 3 + "</p>"
    assert "Han" in foreign_script(mixed, "ar")
    german = "<p>" + "Winterwunder im Naturpark Blidinje erkunden, Skifahren und Wandern. " * 4 + "</p>"
    assert foreign_script(german, "de") == ""
    assert "Cyrillic" in foreign_script(german + "<p>" + "Зимска чуда" * 3 + "</p>", "de")
    assert "only" in foreign_script("<p>" + "Discover the winter wonders of Blidinje nature park. " * 3 + "</p>", "ar")


def test_a_town_swapped_in_translation_is_caught():
    from aoq_worker.stages.translate import swapped_places

    original = {"title": "Touring Mostar: A Day Trip from Medjugorje", "excerpt": "Visit Mostar.", "content_html": "<p>Mostar and the Old Bridge.</p>"}
    assert swapped_places(original, {"title": "Tour of Sarajevo: a day trip from Medjugorje", "content_text": "Mostar and the Old Bridge."}) == ["Sarajevo"]
    # The same town spelled differently is not a swap.
    assert swapped_places(original, {"title": "Touring Mostar: a day trip from Medjuriye", "content_text": "Mostar."}) == []
