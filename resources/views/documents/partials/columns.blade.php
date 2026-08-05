{{--
    La ligne d'en-têtes de colonnes. Elle vit dans la part « Sub-summary by isOption_b (Leading) »
    de la source, ce qui n'est pas un détail d'implémentation : c'est ce qui la fait réapparaître
    en tête du bloc des options, une fois par groupe. D'où un partiel plutôt qu'un `<thead>`.

    Les documents simplifiés et récapitulatifs n'ont que deux colonnes - « Travaux » et le total -
    parce que leur mise en page n'a jamais posé les trois autres.
--}}
<tr class="columns">
    <td style="width:9%"></td>
    <td>{{ $labels->get('works') }}</td>
    @if ($amounts)
        <td style="width:7%">{{ $labels->get('unit') }}</td>
        <td class="num" style="width:9%">{{ $labels->get('quantity') }}</td>
        <td class="num" style="width:14%">{{ $labels->get('unit_price') }}</td>
    @endif
    <td class="num" style="width:15%">{{ $labels->get('total') }}</td>
</tr>
