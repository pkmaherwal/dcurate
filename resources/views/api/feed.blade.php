{!! '<'.'?xml version="1.0" encoding="UTF-8"?>' !!}
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">
    <channel>
        <title>{{ $campaign->name }}</title>
        <link>{{ url('/') }}</link>
        <description>{{ $campaign->description ?? $campaign->name }}</description>
        <language>{{ Str::lower($campaign->language) }}</language>
        <lastBuildDate>{{ now()->toRfc2822String() }}</lastBuildDate>
        @foreach ($items as $item)
            <item>
                <title>{{ $item->displayTitle() }}</title>
                <link>{{ $item->url }}</link>
                <guid isPermaLink="false">{{ $campaign->slug }}-{{ $item->id }}</guid>
                <pubDate>{{ $item->effectiveDate()->toRfc2822String() }}</pubDate>
                @if ($summary = $item->displaySummary())
                    <description>{{ $summary }}</description>
                @endif
                @if ($item->ai_category)
                    <category>{{ $item->ai_category }}</category>
                @endif
                @if ($source = data_get($item->media, 'publisher.name') ?? $item->source?->name)
                    <source url="{{ data_get($item->media, 'publisher.url') ?? $item->source?->publicUrl() ?? $item->url }}">{{ $source }}</source>
                @endif
                @if ($image = $item->imageUrl())
                    <media:content url="{{ $image }}" medium="image" />
                @endif
            </item>
        @endforeach
    </channel>
</rss>
