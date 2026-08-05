{{--
    Un document imprimé d'un métré : les sept boutons du cadre « Documents » de MET_Form passent
    tous par ici. Ce qui change d'un document à l'autre est déclaré par App\Documents\MetreDocument
    et non écrit sept fois - la source, elle, a sept mises en page qui ont visiblement divergé
    (l'une a gardé un total que les autres ont perdu, une autre une colonne de plus).

    La bande de lignes vient de MetreDocumentBuilder : en-têtes de groupe, lignes, composants et
    total sont déjà dans l'ordre d'impression, comme les parts d'un état FileMaker. Ce gabarit ne
    fait que les habiller.

    Volontairement sans Tailwind : dompdf ne lit ni les variables CSS ni les couches, et la
    feuille de l'application ferait un document illisible. Le style tient donc ici, en CSS que
    dompdf comprend, avec les couleurs de la DA écrites en dur.
--}}
<!DOCTYPE html>
<html lang="{{ strtolower($metre->language ?? 'fr') }}">
<head>
    <meta charset="utf-8">
    <title>{{ $filename }}</title>
    <style>
        @page { margin: 18mm 12mm 16mm 12mm; }

        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 7.5pt;
            color: #35160e;           /* sand-950, l'encre de la DA */
            margin: 0;
        }

        /* Le pied de page se répète sur chaque page : dompdf ne sait le faire que par un bloc
           en position fixed, qui se dessine dans la marge de chaque page. */
        .page-footer {
            position: fixed;
            bottom: -12mm; left: 0; right: 0;
            font-size: 6.5pt;
            color: #8a7a72;
            border-top: 0.5pt solid #e4ddd8;
            padding-top: 3pt;
        }
        .page-footer td { border: 0; padding: 0; }

        .doc-head { width: 100%; border-collapse: collapse; margin-bottom: 10pt; }
        .doc-head td { border: 0; padding: 0; vertical-align: top; }
        .doc-title { font-size: 13pt; font-weight: bold; }
        .doc-subtitle { font-size: 8.5pt; color: #6b5c54; padding-top: 2pt; }

        table.rows { width: 100%; border-collapse: collapse; }
        table.rows td { padding: 2pt 3pt; vertical-align: top; }

        .num { text-align: right; white-space: nowrap; }

        tr.columns td {
            border-bottom: 0.75pt solid #35160e;
            font-size: 6.5pt;
            text-transform: uppercase;
            letter-spacing: 0.4pt;
            color: #6b5c54;
            padding-top: 8pt;
        }

        /* Les trois niveaux de titre, du plus fort au plus discret. Un état imprimé se lit à la
           hiérarchie de ses titres : c'est elle qui dit à quel total on a affaire. */
        tr.g-tag1 td { font-size: 9pt; font-weight: bold; background: #efe9e4; padding-top: 6pt; }
        tr.g-tag2 td { font-size: 8pt; font-weight: bold; background: #f6f2ef; }
        tr.g-ref td  { font-size: 8.5pt; font-weight: bold; border-bottom: 0.5pt solid #cdc2ba; padding-top: 6pt; }
        tr.g-refs td { font-size: 7.5pt; font-weight: bold; color: #4a2c22; }

        tr.line td { border-bottom: 0.25pt solid #efe9e4; }
        tr.line .code { color: #8a7a72; white-space: nowrap; }
        tr.option td { font-style: italic; color: #2b5f7a; }   /* info-700 : une ligne en option */

        tr.comment td { color: #6b5c54; font-size: 7pt; padding-top: 0; }
        tr.composition td { color: #8a7a72; font-size: 6.5pt; padding: 1pt 3pt 1pt 14pt; }

        tr.options-head td {
            font-size: 10pt; font-weight: bold; color: #2b5f7a;
            padding-top: 14pt; border-bottom: 0.5pt solid #2b5f7a;
        }

        tr.total td {
            font-size: 9.5pt; font-weight: bold;
            border-top: 1pt solid #35160e; padding-top: 5pt;
        }

        .tail { margin-top: 14pt; }
        .tail-label {
            font-size: 6.5pt; text-transform: uppercase; letter-spacing: 0.4pt; color: #6b5c54;
        }
        .tail-body { white-space: pre-wrap; padding-top: 2pt; }
    </style>
</head>
<body>

@php
    /** @var \App\Documents\MetreDocument $document */
    $amounts = $document->showsLineAmounts();
    // Code, intitulé, total - et trois colonnes de plus quand la ligne porte ses chiffres. Le
    // gabarit s'adapte plutôt que de laisser des cellules vides, qui décaleraient les bordures
    // des titres de groupe.
    $span = $amounts ? 6 : 3;
    $money = fn (?float $v) => $v === null
        ? ''
        : number_format($v, 2, ',', ' ') . ' €';
    $qty = fn ($v) => $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, ',', ' '), '0'), ',');
@endphp

<div class="page-footer">
    <table style="width:100%">
        <tr>
            <td>{{ $printedOn }}</td>
            <td style="text-align:center">{{ $metre->name }}</td>
            {{-- La place du numéro de page, écrit sur le canevas après pagination. --}}
            <td style="text-align:right"></td>
        </tr>
    </table>
</div>

<table class="doc-head">
    <tr>
        <td>
            <div class="doc-title">
                @if ($document->audience() === 'supplier')
                    {{ $labels->get('supplier_title') }}{{ $supplierName ? ' : '.$supplierName : '' }}
                @else
                    {{ $labels->get('title') }}
                @endif
            </div>
            <div class="doc-subtitle">
                {{ $projectLabel }}{{ $projectLabel ? '  ' : '' }}{{ $labels->get('index') }} {{ $metre->ind_project }} - {{ $metre->name }}
            </div>
        </td>
        @if ($logo)
            <td style="width:35%; text-align:right">
                <img src="{{ $logo }}" style="max-height:38pt">
            </td>
        @endif
    </tr>
</table>

<table class="rows">
    {{-- La ligne d'en-têtes de colonnes ouvre le document et se réimprime en tête du bloc des
         options, exactement comme sa part le fait dans la source. --}}
    @include('documents.partials.columns', ['labels' => $labels, 'amounts' => $amounts])

    @foreach ($rows as $row)
        @switch($row['kind'])

            @case('option-break')
                @if ($row['isOption'])
                    <tr class="options-head"><td colspan="{{ $span }}">{{ $labels->get('options') }}</td></tr>
                    @include('documents.partials.columns', ['labels' => $labels, 'amounts' => $amounts])
                @endif
                @break

            @case('group')
                <tr class="g-{{ $row['level'] }}">
                    <td colspan="{{ $span - 1 }}">
                        @if ($row['code'] !== null){{ $row['code'] }}&nbsp;&nbsp;@endif{{ $row['title'] }}
                    </td>
                    <td class="num">{{ $money($row['total']) }}</td>
                </tr>
                @break

            @case('line')
                <tr class="line {{ $row['isOption'] ? 'option' : '' }}">
                    <td class="code">{{ $row['code'] }}</td>
                    {{-- Sans chiffres, l'intitulé prend la place des colonnes absentes : la
                         source ne montre alors que le code et le libellé du poste. --}}
                    <td @if (! $amounts) colspan="{{ $span - 1 }}" @endif>{{ $row['title'] }}</td>
                    @if ($amounts)
                        <td>{{ $row['unit'] }}</td>
                        <td class="num">{{ $qty($row['quantity']) }}</td>
                        <td class="num">{{ $money($row['price']) }}</td>
                        <td class="num">{{ $money($row['total']) }}</td>
                    @endif
                </tr>
                @if ($row['comment'])
                    <tr class="comment {{ $row['isOption'] ? 'option' : '' }}">
                        <td></td><td colspan="{{ $span - 1 }}">{{ $row['comment'] }}</td>
                    </tr>
                @endif
                @break

            @case('composition')
                <tr class="composition">
                    <td></td>
                    <td colspan="{{ $span - 2 }}">{{ $row['description'] }}</td>
                    <td class="num">{{ $qty($row['value']) }}</td>
                </tr>
                @break

            @case('total')
                <tr class="total">
                    <td colspan="{{ $span - 1 }}">{{ $labels->get('total') }}</td>
                    <td class="num">{{ $money($row['total']) }}</td>
                </tr>
                @break

        @endswitch
    @endforeach
</table>

@if ($tailComment)
    <div class="tail">
        <div class="tail-label">{{ $labels->get('comments') }}</div>
        <div class="tail-body">{{ $tailComment }}</div>
    </div>
@endif

{{-- Le numéro de page n'est pas ici : dompdf ne connaît la pagination qu'une fois la mise en
     page faite. Il est posé sur le canevas par MetreDocumentController::stampPageNumbers(),
     plutôt qu'en activant l'exécution de PHP dans les gabarits, qui est ce que documente dompdf
     et que ce projet n'a aucune raison d'ouvrir. --}}

</body>
</html>
