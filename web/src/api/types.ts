import type { operations } from './schema'

type Json<Op extends keyof operations> = operations[Op] extends { responses: { 200: { content: { 'application/json': infer T } } } } ? T : never

export type VideoPage = Json<'publicVideos.latest'>
export type VideoTileData = VideoPage['items'][number]
export type PublicUser = Json<'profile.show'>
export type Category = Json<'category.index'>['items'][number]
