import type { operations } from './schema'

type Json<Op extends keyof operations> = operations[Op] extends { responses: { 200: { content: { 'application/json': infer T } } } } ? T : never

export type VideoPage = Json<'publicVideos.latest'>
export type VideoTileData = VideoPage['items'][number]
export type PublicUser = Json<'profile.show'>
export type Category = Json<'category.index'>['items'][number]
export type VideoDetail = Json<'video.show'>

export type PlaybackCaption = { language: string; label: string; kind: string; url: string }
export type PlaybackThumbnail = { url: string; width: number; height: number; format: string }
type PlaybackResponse = Json<'playback.show'>
/** The generated schema leaves `captions` and `thumbnails.primary` as `unknown[]`; this is the real shape. */
export type Playback = Omit<PlaybackResponse, 'captions' | 'thumbnails'> & {
  captions: PlaybackCaption[]
  thumbnails: { primary: PlaybackThumbnail[] }
}
