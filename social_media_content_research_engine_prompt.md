# Social Media Content Research & Daily Content Ideas Engine

## 1. Objective

Build a scalable **Social Media Content Research & Daily Content Ideas**
system.

The system must allow the user to manage multiple social media platforms
and channels. Each channel can have its own:

-   niche
-   sub-niches
-   target audience
-   keywords
-   excluded keywords
-   content style
-   content format
-   research sources
-   scheduler
-   content frequency

The system must perform automated research based on each channel's niche
and generate new content ideas every day.

### Core principle

**Do not hardcode channels, niches, or research sources into business
logic.**

The system must be **configuration-driven** so that new channels,
platforms, niches, and research sources can be added from the UI without
modifying the scheduler or core research engine.

------------------------------------------------------------------------

# 2. Platform vs Channel

Keep `Platform` and `Channel` as separate concepts.

Example:

``` text
YouTube
├── Taofik Basuki
├── Movie Script
├── Mr. Off-Peak
└── Exist Gaming

TikTok
├── Taofik Basuki
└── Mr. Off-Peak

Instagram
└── Taofik Basuki
```

A platform can have multiple channels.

A channel belongs to one platform but should be able to have its own
content strategy and research configuration.

Initial supported platform examples:

-   YouTube
-   TikTok
-   Instagram
-   Facebook
-   X
-   Threads
-   Website
-   Podcast

The architecture must allow additional platforms later.

------------------------------------------------------------------------

# 3. Initial Channel Seed Data

Use the following channels as initial seed data.

## 3.1 Taofik Basuki

### Niche

-   Technology
-   Programming
-   AI
-   Server
-   Linux
-   Networking
-   Automation
-   Developer Experience

### Content Style

-   Tutorial
-   Experiment
-   Tech commentary
-   Personal experience

### Research Sources

-   Google Trends
-   YouTube
-   Reddit
-   GitHub
-   Hacker News
-   Dev.to
-   Stack Overflow
-   Product Hunt
-   Technology news
-   RSS

------------------------------------------------------------------------

## 3.2 Netizen Muslim

### Niche

-   Islam
-   Islamic issues
-   Muslim world
-   Social issues
-   Islamic history
-   Current events related to Muslims

### Content Style

-   News analysis
-   Commentary
-   Educational
-   Storytelling

### Research Sources

-   Google Trends
-   Google News
-   Reddit
-   YouTube
-   News RSS
-   Configurable relevant news sources

------------------------------------------------------------------------

## 3.3 Kang Basooki

### Niche

-   Personal commentary
-   Daily life
-   Social phenomena
-   Internet culture
-   Interesting stories
-   Humor

### Content Style

-   Casual commentary
-   Storytelling
-   Opinion
-   Entertainment

### Research Sources

-   Google Trends
-   YouTube
-   Reddit
-   News/RSS
-   Web Search
-   Social trend sources where officially accessible

------------------------------------------------------------------------

## 3.4 Mr. Off-Peak

### Niche

-   News
-   Trending topics
-   Viral events
-   Indonesian social issues
-   International news
-   Internet drama
-   Public-interest events

### Content Style

-   Fast commentary
-   News analysis
-   Opinion
-   Satirical commentary

### Research Sources

-   Google Trends
-   Google News
-   YouTube
-   Reddit
-   RSS
-   Social trend sources where officially accessible

This channel should be optimized for fresh and rapidly changing topics.

It may run research multiple times per day.

Example schedule:

``` text
06:00
12:00
18:00
21:00
```

The schedule must remain configurable.

------------------------------------------------------------------------

## 3.5 Movie Script

### Niche

-   Movies
-   Movie stories
-   Movie explanations
-   Ending explained
-   Movie theories
-   Character analysis
-   Interesting movie facts

### Content Style

-   Storytelling
-   Movie recap
-   Explanation
-   Mystery/twist storytelling

### Research Sources

-   Google Trends
-   YouTube
-   Reddit
-   TMDB
-   IMDb
-   Movie news
-   Google Search

------------------------------------------------------------------------

## 3.6 Fakultas Teknik Unwim

### Niche

-   Engineering
-   Civil engineering
-   Architecture
-   Construction
-   Building technology
-   AutoCAD
-   SketchUp
-   BIM
-   Engineering education

### Content Style

-   Educational
-   Tutorial
-   Academic
-   Practical knowledge

### Research Sources

-   Google Trends
-   YouTube
-   Google Search
-   Reddit
-   Engineering news
-   Academic/publication RSS
-   Relevant configurable sources

------------------------------------------------------------------------

## 3.7 Kang Basooki React

### Niche

-   Viral videos
-   Internet culture
-   Funny videos
-   Movie trailers
-   Technology videos
-   Interesting internet content

### Content Style

-   Reaction
-   Commentary
-   Entertainment

### Research Sources

-   YouTube
-   Google Trends
-   Reddit
-   Viral/trending sources
-   RSS
-   Social trend sources where officially accessible

------------------------------------------------------------------------

## 3.8 Exist Gaming

### Niche

-   Gaming
-   New games
-   Popular games
-   Gameplay
-   Gaming tips
-   Walkthrough
-   Gaming news
-   Gaming trends

### Content Style

-   Gameplay
-   Tips & tricks
-   Challenge
-   Funny moments
-   Shorts
-   Live content

### Research Sources

-   Google Trends
-   YouTube
-   Reddit
-   Steam
-   Twitch
-   Game news
-   Game release sources
-   Google Play
-   Other configurable gaming sources

------------------------------------------------------------------------

# 4. Adding a New Channel

Adding a new channel must NOT require code changes.

Provide:

``` text
Channels
→ Add Channel
```

Allow the user to configure:

``` text
Channel Name
Platform
Handle
Description

Niche
Sub Niches

Target Audience
Language
Timezone

Content Style
Content Types
Content Formats
Tone
Hook Styles

Keywords
Excluded Keywords

Research Sources

Scheduler Enabled
Research Frequency
Research Time
Ideas Per Run
```

Example:

``` text
Channel Name:
AI Indonesia

Platform:
YouTube

Niche:
Artificial Intelligence

Sub Niches:
- AI tools
- AI news
- Generative AI
- AI tutorials
- AI business

Target Audience:
Indonesian technology enthusiasts

Language:
Indonesian

Content Style:
- News
- Tutorial
- Commentary

Keywords:
AI
ChatGPT
Claude
Gemini
LLM
Generative AI
AI tools

Research Sources:
☑ Google Trends
☑ YouTube
☑ Reddit
☑ Google News
☑ Hacker News
☑ Product Hunt

Scheduler:
Enabled

Research Time:
06:00

Ideas Per Run:
5
```

The scheduler must automatically include this channel after it is
enabled.

------------------------------------------------------------------------

# 5. Channel Templates

Create optional reusable channel templates.

Examples:

``` text
YouTube News
YouTube Technology
YouTube Gaming
YouTube Movie
Islamic News
Reaction
Educational
Custom
```

When a template is selected, pre-populate:

-   niche
-   content types
-   research sources
-   default scheduler
-   content formats
-   tone

The user can modify the defaults before saving.

------------------------------------------------------------------------

# 6. Niche Configuration

Niche configuration must be database-driven.

Do not implement:

``` text
if channel == "Taofik Basuki"
```

or:

``` text
if channel == "Movie Script"
```

Instead use:

``` text
channel.niche
channel.sub_niches
channel.keywords
channel.excluded_keywords
channel.content_strategy
```

A new niche should work automatically without code changes.

------------------------------------------------------------------------

# 7. Research Source Architecture

Implement a pluggable research provider architecture.

Create:

``` text
ResearchProviderInterface
```

Possible methods:

``` text
search()
trending()
getDetails()
normalize()
healthCheck()
```

Initial providers should support:

1.  Google Trends
2.  Reddit
3.  YouTube
4.  Google News
5.  Web Search
6.  RSS
7.  GitHub
8.  Hacker News
9.  Product Hunt
10. TMDB / movie data
11. IMDb or compatible movie data provider
12. Steam
13. Twitch
14. Google Play
15. TikTok trends where officially accessible
16. Other configurable sources

Providers must be independent.

Examples:

``` text
RedditResearchProvider
GoogleTrendsResearchProvider
YouTubeResearchProvider
GoogleNewsResearchProvider
RssResearchProvider
GitHubResearchProvider
HackerNewsResearchProvider
ProductHuntResearchProvider
MovieResearchProvider
GamingResearchProvider
```

Adding a new provider should not require changing the core research
engine.

------------------------------------------------------------------------

# 8. Research Sources by Channel

Research sources must be configurable per channel.

Example:

``` text
Taofik Basuki
├── Google Trends
├── YouTube
├── Reddit
├── GitHub
├── Hacker News
├── Dev.to
├── Stack Overflow
└── Product Hunt

Movie Script
├── Google Trends
├── YouTube
├── Reddit
├── TMDB
├── IMDb
└── Movie News

Exist Gaming
├── Google Trends
├── YouTube
├── Reddit
├── Steam
├── Twitch
└── Game News
```

Do not force every channel to use every provider.

------------------------------------------------------------------------

# 9. Research Source Configuration

Each source should support configuration such as:

``` text
source_name
provider
enabled
keywords
subreddits
RSS URLs
regions
language
categories
minimum_score
research_frequency
priority
weight
configuration
```

Example Reddit configuration:

``` text
Subreddits:

r/programming
r/artificial
r/LocalLLaMA
r/selfhosted
r/linux

Keywords:

AI
LLM
Docker
Linux
PHP
Laravel
GPU

Minimum Upvotes:
50

Research Period:
Last 24 hours
```

The UI must allow source-specific configuration.

------------------------------------------------------------------------

# 10. Multi-Source Research

Do not rely on a single source.

Example:

``` text
Reddit
↓
Topic is being discussed

Google Trends
↓
Search interest is rising

YouTube
↓
New videos are appearing

Google News
↓
Media is reporting the topic
```

The research engine should correlate these signals.

A topic found across multiple independent sources should receive a
higher confidence score.

------------------------------------------------------------------------

# 11. Cross-Source Validation

Do not assume a topic is trending because it appears in only one source.

Example:

``` text
Reddit:
Topic A trending

Google Trends:
Topic A rising

YouTube:
Several new videos about Topic A

Google News:
Topic A being reported
```

Result:

``` text
cross_source_score = HIGH
```

If only one weak source mentions the topic:

``` text
cross_source_score = LOW
```

Use cross-source evidence in ranking.

------------------------------------------------------------------------

# 12. Research Pipeline

The complete pipeline should be:

``` text
Channel
↓
Load Channel Niche
↓
Load Target Audience
↓
Load Content Strategy
↓
Load Research Source Configuration
↓
Generate Search Queries
↓
Query Multiple Sources
↓
Normalize Results
↓
Remove Duplicate Sources
↓
Extract Topics
↓
Cross-Source Correlation
↓
Trend Scoring
↓
Niche Relevance Scoring
↓
Content Opportunity Scoring
↓
AI Content Idea Generation
↓
Duplicate Content Check
↓
Rank Ideas
↓
Save Research Evidence
↓
Save Content Ideas
```

------------------------------------------------------------------------

# 13. Daily Research Scheduler

Create an automated scheduler.

Example:

``` text
Daily at 06:00
```

Process:

``` text
Find all channels where scheduler_enabled = true
↓
For each channel
↓
Load its research profile
↓
Run configured research providers
↓
Generate content ideas
↓
Rank ideas
↓
Save results
```

Use queue/background jobs if the application already has a queue system.

Recommended architecture:

``` text
DailyContentResearchCommand
        ↓
Dispatch ResearchChannelJob
        ↓
Research Providers
        ↓
Normalize Results
        ↓
AI Content Idea Generator
        ↓
Save Content Ideas
        ↓
Update Research Log
```

Do not run the complete multi-channel research process synchronously
inside an HTTP request.

If one channel fails, other channels must continue.

------------------------------------------------------------------------

# 14. Multiple Runs Per Day

The scheduler must support:

``` text
Once daily
Twice daily
Every N hours
Custom schedule
```

Example:

``` text
Mr. Off-Peak:
06:00
12:00
18:00
21:00
```

While:

``` text
Fakultas Teknik Unwim:
06:00
```

The schedule must be configurable per channel.

Use the configured timezone.

Default timezone:

``` text
Asia/Jakarta
```

------------------------------------------------------------------------

# 15. Number of Ideas

Default:

``` text
3-5 ideas per channel per research run
```

Allow configuration:

``` text
Ideas Per Run:
1
3
5
10
Custom
```

------------------------------------------------------------------------

# 16. Content Idea Structure

Each generated content idea should contain:

``` text
channel_id
research_date
topic
title
alternative_titles
short_description
content_angle
why_this_topic
target_audience
keywords
source_urls
source_summary
trend_score
relevance_score
originality_score
cross_source_score
priority_score
suggested_content_type
suggested_format
status
```

Example:

``` text
Channel:
Taofik Basuki

Topic:
Local AI Coding Assistant

Title:
AI Coding Assistant Lokal yang Bisa Jalan Tanpa Kirim Source Code ke Cloud

Short Description:
Exploring how local AI coding assistants work, what hardware is required, and whether they are practical for everyday development.

Content Angle:
Privacy + developer productivity + local AI

Suggested Content:
YouTube video + Shorts

Trend Score:
85

Relevance Score:
94

Originality Score:
81

Priority Score:
88
```

------------------------------------------------------------------------

# 17. AI Content Idea Generator

Create a reusable AI prompt template.

Inputs:

``` text
CHANNEL_NICHE
SUB_NICHES
TARGET_AUDIENCE
CONTENT_STYLE
CONTENT_TYPES
CONTENT_FORMATS
KEYWORDS
EXCLUDED_KEYWORDS
RESEARCH_RESULTS
PREVIOUS_CONTENT
CURRENT_DATE
```

The AI must generate structured JSON.

Example:

``` json
{
  "topic": "...",
  "title": "...",
  "alternative_titles": [
    "...",
    "...",
    "..."
  ],
  "short_description": "...",
  "content_angle": "...",
  "why_this_topic": "...",
  "target_audience": "...",
  "keywords": [],
  "suggested_content_type": "youtube",
  "suggested_format": "long_form",
  "trend_score": 85,
  "relevance_score": 94,
  "originality_score": 81,
  "priority_score": 88,
  "sources": []
}
```

When structured output is required, do not allow free-form output
outside the required JSON structure.

------------------------------------------------------------------------

# 18. Content Scoring

Implement configurable scoring.

At minimum:

``` text
trend_score
relevance_score
engagement_score
freshness_score
originality_score
cross_source_score
```

Example default formula:

``` text
priority_score =
    trend_score * 0.30 +
    relevance_score * 0.30 +
    originality_score * 0.15 +
    freshness_score * 0.10 +
    engagement_score * 0.10 +
    cross_source_score * 0.05
```

Make weights configurable.

Scores should be normalized to 0-100.

------------------------------------------------------------------------

# 19. Duplicate Detection

Before generating or saving an idea:

Check previous content and ideas.

Compare:

-   title
-   topic
-   keywords
-   semantic similarity if possible
-   previous research
-   draft content
-   published content

Check statuses:

``` text
IDEA
SELECTED
SCRIPTING
DRAFT
APPROVED
PUBLISHED
REJECTED
```

If a new topic is too similar to existing content, do not create it as a
new primary idea.

------------------------------------------------------------------------

# 20. Same Research, Different Channels

The same research topic may be relevant to multiple channels.

The system should allow this, but generate a different content angle for
each channel.

Example:

Research topic:

``` text
Open-source AI coding agent is trending.
```

For:

``` text
Taofik Basuki
```

Generate:

``` text
Angle:
Developer experience

Title:
Saya Coba AI Coding Agent Open Source Ini
```

For:

``` text
AI Indonesia
```

Generate:

``` text
Angle:
AI education

Title:
AI Coding Agent Open Source: Apa yang Bisa Dilakukannya?
```

For:

``` text
Tech Daily
```

Generate:

``` text
Angle:
Technology news

Title:
AI Coding Agent Open Source Ini Sedang Ramai Dibicarakan
```

The research can be shared, but content strategy must remain
channel-specific.

------------------------------------------------------------------------

# 21. Research Evidence

Every content idea must retain its research evidence.

Store:

``` text
source
source_url
source_title
published_at
discovered_at
engagement_metrics
source_score
extracted_summary
```

The UI must provide:

``` text
View Research
```

so the user can understand why the system recommended the topic.

------------------------------------------------------------------------

# 22. Research Transparency

Never fabricate research data.

If a provider is unavailable:

``` text
mark provider as unavailable
log the error
continue with other providers
mark the research run as PARTIAL when appropriate
```

Never invent:

-   trends
-   search volume
-   Reddit votes
-   YouTube views
-   news articles
-   URLs
-   engagement metrics
-   sources

Only use retrieved data.

------------------------------------------------------------------------

# 23. Research History

Create a research execution history.

Store:

``` text
channel_id
execution_time
status
topics_found
ideas_generated
error_message
execution_duration
providers_used
providers_failed
```

Statuses:

``` text
RUNNING
SUCCESS
PARTIAL
FAILED
```

------------------------------------------------------------------------

# 24. Content Pipeline

Initial implementation should stop at Content Ideas.

Future-ready pipeline:

``` text
Research
↓
Content Ideas
↓
Select Idea
↓
Generate Script
↓
Review
↓
Approve
↓
Generate Media
↓
Publish
```

Do NOT automatically publish in this phase.

------------------------------------------------------------------------

# 25. Dashboard

Create a central dashboard showing:

-   Today's research
-   New content ideas
-   High-priority ideas
-   Trending topics
-   Ideas by channel
-   Ideas waiting for review
-   Previously used topics
-   Scheduler status
-   Last successful research
-   Next scheduled research
-   Research errors
-   Provider health

Filters:

``` text
Channel
Platform
Date
Niche
Priority
Status
Content Type
Research Source
```

------------------------------------------------------------------------

# 26. Channel Detail Page

Display:

## Channel Profile

``` text
Name
Platform
Handle
Niche
Sub Niches
Target Audience
Language
Timezone
Content Style
Content Types
Content Formats
Keywords
Excluded Keywords
```

## Research Settings

``` text
Scheduler Enabled
Research Frequency
Research Time
Ideas Per Run
Minimum Relevance Score
Minimum Trend Score
```

## Research Sources

Show enabled sources and their configuration.

## Content Ideas

Show all ideas generated for the channel.

------------------------------------------------------------------------

# 27. Research Source Management UI

Create a page:

``` text
Settings
→ Research Sources
```

Display:

``` text
Google Trends     Enabled
Reddit            Enabled
YouTube           Enabled
Google News       Enabled
RSS               Enabled
GitHub            Enabled
Hacker News       Enabled
Product Hunt      Enabled
TMDB              Enabled
Steam             Enabled
Twitch            Enabled
```

Allow:

``` text
Enable
Disable
Configure
Test Connection
View Last Error
View Last Successful Run
```

------------------------------------------------------------------------

# 28. Platform-Aware Content Strategy

A channel's content strategy should consider the platform.

For example:

## YouTube

-   Long-form
-   Shorts
-   Educational
-   Storytelling
-   Commentary

## TikTok

-   Short-form
-   Strong hook
-   Fast pacing
-   Trending topics

## Instagram

-   Reels
-   Carousel
-   Short-form

## Facebook

-   Video
-   Reels
-   Longer commentary

The system must allow these strategies to be configured rather than
hardcoded.

------------------------------------------------------------------------

# 29. Database Design

Use a normalized, scalable structure.

Suggested entities:

``` text
platforms

channels

channel_niches

channel_audiences

content_strategies

research_profiles

research_sources

channel_research_sources

research_runs

research_results

content_ideas

content_idea_sources

channel_templates
```

Example:

``` text
platforms
    id
    name
    type

channels
    id
    platform_id
    name
    handle
    description
    language
    timezone
    enabled

channel_niches
    id
    channel_id
    niche
    sub_niches
    keywords
    excluded_keywords

channel_audiences
    id
    channel_id
    target_audience

content_strategies
    id
    channel_id
    content_types
    content_formats
    tone
    hook_styles

research_profiles
    id
    channel_id
    enabled
    frequency
    schedule
    ideas_per_run

research_sources
    id
    name
    provider
    type
    enabled

channel_research_sources
    id
    channel_id
    research_source_id
    weight
    configuration
```

------------------------------------------------------------------------

# 30. API Design

Create appropriate API endpoints for:

``` text
Platforms
Channels
Channel Niches
Content Strategies
Research Profiles
Research Sources
Research Runs
Research Results
Content Ideas
Templates
```

Example endpoints:

``` text
GET    /api/platforms
POST   /api/platforms

GET    /api/channels
POST   /api/channels
GET    /api/channels/{id}
PUT    /api/channels/{id}
DELETE /api/channels/{id}

GET    /api/channels/{id}/research
GET    /api/channels/{id}/content-ideas

POST   /api/channels/{id}/research/run
POST   /api/content-ideas/{id}/select
```

Follow the project's existing API conventions instead of blindly using
these exact routes.

------------------------------------------------------------------------

# 31. Scheduler Architecture

Use the existing scheduler/cron architecture if available.

Recommended:

``` text
DailyContentResearchCommand
    ↓
Find enabled research profiles
    ↓
Dispatch ResearchChannelJob
    ↓
ResearchChannelJob
    ↓
Run configured ResearchProviders
    ↓
Normalize results
    ↓
Correlate sources
    ↓
Score topics
    ↓
Generate AI content ideas
    ↓
Duplicate detection
    ↓
Save ideas and evidence
```

Each channel must be independently fault tolerant.

------------------------------------------------------------------------

# 32. Error Handling

If one provider fails:

``` text
Reddit FAILED
Google Trends SUCCESS
YouTube SUCCESS
Google News SUCCESS
```

The channel should still receive research results.

Research run status:

``` text
PARTIAL
```

Store the provider error.

Do not fail the complete system because one provider is unavailable.

------------------------------------------------------------------------

# 33. Logging and Observability

Log:

-   scheduler start
-   scheduler completion
-   channel processing
-   provider execution
-   provider failures
-   API rate limit errors
-   AI errors
-   duplicate detection
-   number of topics found
-   number of ideas generated
-   execution duration

Provide enough information to debug a failed daily research run.

------------------------------------------------------------------------

# 34. Rate Limits and API Safety

Research providers may have rate limits.

Implement:

-   retries where appropriate
-   exponential backoff
-   provider-specific rate limits
-   caching where appropriate
-   request timeout
-   API credential management
-   provider health status

Do not repeatedly call an unavailable provider.

------------------------------------------------------------------------

# 35. Security

API credentials must NOT be stored in plain text where the existing
application supports secure secrets/configuration.

Do not expose API keys in:

-   frontend
-   logs
-   API responses
-   research results
-   browser developer tools

Respect provider terms of service and use official APIs where available.

------------------------------------------------------------------------

# 36. Internationalization

Channel language must be configurable.

Example:

``` text
Indonesian
English
```

The research query and AI content generation should use the configured
language.

The system should support additional languages later.

------------------------------------------------------------------------

# 37. Important Rules

1.  Never mix channel niches accidentally.
2.  Every channel has its own research context.
3.  Do not hardcode channel names in business logic.
4.  Do not hardcode niches in business logic.
5.  Do not hardcode research sources in the scheduler.
6.  New channels must be addable through UI.
7.  New platforms must be addable without redesigning the research
    engine.
8.  New research providers must be pluggable.
9.  Do not generate duplicate content ideas.
10. Prioritize fresh and relevant topics.
11. Store research evidence.
12. Never fabricate sources or research metrics.
13. Do not automatically publish content.
14. Research must run asynchronously/background when possible.
15. One provider failure must not stop other providers.
16. One channel failure must not stop other channels.
17. Scheduler configuration must be per channel.
18. Research source configuration must be per channel.
19. Content strategy must be per channel/platform.
20. Use Asia/Jakarta as the default timezone.
21. Reuse existing project architecture and conventions.
22. Do not introduce a new framework or major architecture unless
    necessary.
23. Do not remove or break existing functionality.

------------------------------------------------------------------------

# 38. Existing Project Inspection

Before implementing anything:

1.  Inspect the existing project structure.
2.  Identify framework and version.
3.  Identify database architecture.
4.  Identify existing models/entities.
5.  Identify existing scheduler/cron implementation.
6.  Identify existing queue/job system.
7.  Identify existing AI integration.
8.  Identify existing authentication/authorization.
9.  Identify existing API conventions.
10. Identify existing frontend architecture.
11. Identify existing design system/components.
12. Identify existing notification system.
13. Identify existing logging/error handling.
14. Identify existing configuration/secrets management.

Reuse existing functionality whenever possible.

Do not create duplicate infrastructure.

------------------------------------------------------------------------

# 39. Implementation Plan Requirement

Before coding, provide an implementation plan containing:

``` text
1. Existing architecture analysis
2. Database changes
3. Models/entities
4. Services
5. Research provider architecture
6. Scheduler
7. Queue/jobs
8. AI integration
9. API endpoints
10. Frontend pages/components
11. Permissions
12. Tests
13. Migration strategy
14. Deployment considerations
```

Then implement the feature end-to-end.

------------------------------------------------------------------------

# 40. Testing

Add tests for:

## Channel

-   create channel
-   update channel
-   enable/disable scheduler
-   configure niche
-   configure research sources

## Research

-   provider execution
-   provider failure
-   normalization
-   duplicate research results
-   cross-source correlation
-   scoring
-   niche filtering

## AI

-   structured JSON parsing
-   invalid AI response
-   missing fields
-   content idea generation
-   duplicate idea detection

## Scheduler

-   only enabled channels are processed
-   correct schedule is respected
-   multiple channels are processed independently
-   one channel failure does not stop others

## Content Ideas

-   correct channel association
-   correct niche
-   duplicate prevention
-   scoring
-   source evidence

------------------------------------------------------------------------

# 41. Acceptance Criteria

The feature is considered complete when:

### Add Channel

I can create a completely new channel from the UI without changing
source code.

### Configure Niche

I can define:

``` text
Niche
Sub Niches
Keywords
Excluded Keywords
Audience
Content Style
```

### Configure Sources

I can select different research sources for every channel.

### Configure Scheduler

I can configure:

``` text
Enabled
Frequency
Time
Timezone
Ideas Per Run
```

### Daily Research

The scheduler automatically researches enabled channels.

### Multi-Source

The system can combine multiple research sources.

### Evidence

Every content idea shows the research sources that caused the
recommendation.

### Duplicate Detection

The system does not repeatedly recommend the same content.

### Ranking

Ideas receive meaningful scores.

### Fault Tolerance

Provider or channel failures do not stop the entire scheduler.

### Extensibility

Adding a new channel does not require code changes.

Adding a new research provider only requires implementing the provider
interface and registering it.

------------------------------------------------------------------------

# 42. Target Architecture

The final conceptual architecture should be:

``` text
                         PLATFORM
                            │
                            ↓
                         CHANNEL
                            │
              ┌─────────────┼─────────────┐
              ↓             ↓             ↓
            NICHE        AUDIENCE     STRATEGY
              │             │             │
              └─────────────┼─────────────┘
                            ↓
                    RESEARCH PROFILE
                            │
              ┌─────────────┼─────────────┐
              ↓             ↓             ↓
           REDDIT       GOOGLE TRENDS   YOUTUBE
              │             │             │
              ↓             ↓             ↓
          GOOGLE NEWS       RSS         GITHUB
              │             │             │
              └─────────────┼─────────────┘
                            ↓
                    RESEARCH ENGINE
                            ↓
                  SOURCE CORRELATION
                            ↓
                     TREND SCORING
                            ↓
                  NICHE RELEVANCE
                            ↓
                  CONTENT OPPORTUNITY
                            ↓
                   AI IDEA GENERATOR
                            ↓
                  DUPLICATE DETECTION
                            ↓
                    PRIORITY RANKING
                            ↓
                     CONTENT IDEAS
                            ↓
                      HUMAN REVIEW
                            ↓
                   FUTURE: SCRIPTING
                            ↓
                   FUTURE: PRODUCTION
                            ↓
                   FUTURE: PUBLISHING
```

The most important architectural requirement is:

> **Configuration-driven, multi-channel, multi-platform, multi-source,
> extensible, and fault-tolerant.**

The system should behave like a **Content Research Engine**, not just a
daily AI title generator.
